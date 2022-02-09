<?php

declare(strict_types=1);

namespace Mallgroup\RoadRunner\Http;

use Nette\Http\FileUpload;
use Nette\Http\Helpers;
use Nette\Http\Request;
use Nette\Http\Url;
use Nette\Http\UrlScript;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class RequestFactory
{
	/** @var string[] */
	private array $proxies = [];

	/** @param string[] $proxies */
	public function setProxy(array $proxies): void
	{
		$this->proxies = $proxies;
	}

	public function getRequest(ServerRequestInterface $request): Request
	{
		$url = $this->createUrl($request);

		[$remoteAddr, $remoteHost] = $this->resolveClientAttributes($url, $request);

		return new Request(
			new UrlScript($url, $this->getScriptPath($url, $request)),
			$this->getPost($request),
			$this->getUploadedFiles($request),
			$request->getCookieParams(),
			$this->getHeaders($request),
			$request->getMethod(),
			$remoteAddr,
			$remoteHost,
			fn(): string => (string) $request->getBody()
		);
	}

	private function getScriptPath(Url $url, ServerRequestInterface $request): string
	{
		$path = $url->getPath();
		$lpath = strtolower($path);
		$script = strtolower($request->getServerParams()['SCRIPT_NAME'] ?? '');
		if ($lpath !== $script) {
			$max = min(strlen($lpath), strlen($script));
			for ($i = 0; $i < $max && $lpath[$i] === $script[$i]; $i++) ;
			$path = $i
				? substr($path, 0, strrpos($path, '/', $i - strlen($path) - 1) + 1)
				: '/';
		}
		return $path;
	}

	/**
	 * @return string[]
	 */
	private function resolveClientAttributes(Url $url, ServerRequestInterface $request): array
	{
		$serverParams = $request->getServerParams();

		$remoteAddr = $serverParams['REMOTE_ADDR'] ?? ($request->getHeader('REMOTE_ADDR')[0] ?? null);
		$remoteHost = $serverParams['REMOTE_HOST'] ?? ($request->getHeader('REMOTE_HOST')[0] ?? null);

		$usingTrustedProxy = $remoteAddr
			&& !empty(array_filter(
				$this->proxies,
				fn (string $proxy): bool => Helpers::ipMatch($remoteAddr, $proxy)
			));

		if ($usingTrustedProxy) {
			[$remoteAddr, $remoteHost] = empty($request->getHeader('HTTP_FORWARDED'))
			? $this->useNonstandardProxy($url, $request, $remoteAddr, $remoteHost)
			: $this->useForwardedProxy($url, $request, $remoteAddr, $remoteHost);
		}

		return [$remoteAddr, $remoteHost];
	}

	/**
	 * @return array<int, string|null>
	 */
	private function useForwardedProxy(
		Url $url,
		ServerRequestInterface $request,
		?string $remoteAddr,
		?string $remoteHost
	): array {
		$forwardParams = $request->getHeader('HTTP_FORWARDED');
		$proxyParams = [];
		/** @var array<int, string> $forwardParams */
		foreach ($forwardParams as $forwardParam) {
			[$key, $value] = explode('=', $forwardParam, 2) + [1 => ''];
			$proxyParams[strtolower(trim($key))][] = trim($value, " \t\"");
		}

		if (isset($proxyParams['for'])) {
			$address = $proxyParams['for'][0];
			$remoteAddr = !str_contains($address, '[')
				? explode(':', $address)[0]  // IPv4
				: substr($address, 1, strpos($address, ']') - 1); // IPv6
		}

		if (isset($proxyParams['host']) && count($proxyParams['host']) === 1) {
			$host = $proxyParams['host'][0];
			$startingDelimiterPosition = strpos($host, '[');
			if ($startingDelimiterPosition === false) { //IPv4
				$remoteHostArr = explode(':', $host);
				$remoteHost = $remoteHostArr[0];
				$url->setHost($remoteHost);
			} else { //IPv6
				$endingDelimiterPosition = (int) strpos($host, ']');
				$remoteHost = substr($host, strpos($host, '[') + 1, $endingDelimiterPosition - 1);
				$url->setHost($remoteHost);
				$remoteHostArr = explode(':', substr($host, $endingDelimiterPosition));
			}
			if (isset($remoteHostArr[1])) {
				$url->setPort((int)$remoteHostArr[1]);
			}
		}

		$scheme = (isset($proxyParams['proto']) && count($proxyParams['proto']) === 1)
			? $proxyParams['proto'][0]
			: 'http';
		$url->setScheme(strcasecmp($scheme, 'https') === 0 ? 'https' : 'http');

		return [$remoteAddr, $remoteHost];
	}

	/**
	 * @return array<int, string|null>
	 */
	private function useNonstandardProxy(
		Url $url,
		ServerRequestInterface $request,
		?string $remoteAddr,
		?string $remoteHost
	): array {

		if (isset($request->getHeader('HTTP_X_FORWARDED_PROTO')[0])) {
			$url->setScheme(
				strcasecmp($request->getHeader('HTTP_X_FORWARDED_PROTO')[0], 'https') === 0 ? 'https' : 'http'
			);
			$url->setPort($url->getScheme() === 'https' ? 443 : 80);
		}

		if (isset($request->getHeader('HTTP_X_FORWARDED_PORT')[0])) {
			$url->setPort((int)$request->getHeader('HTTP_X_FORWARDED_PORT')[0]);
		}

		if (!empty($request->getHeader('HTTP_X_FORWARDED_FOR'))) {
			$xForwardedForWithoutProxies = array_filter(
				$request->getHeader('HTTP_X_FORWARDED_FOR'),
				function (string $ip): bool {
					return !array_filter($this->proxies, function (string $proxy) use ($ip): bool {
						return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false
							&& Helpers::ipMatch(trim($ip), $proxy);
					});
				}
			);
			if ($xForwardedForWithoutProxies) {
				$remoteAddr = trim(end($xForwardedForWithoutProxies));
				$xForwardedForRealIpKey = key($xForwardedForWithoutProxies);
			}
		}

		if (isset($xForwardedForRealIpKey) && !empty($request->getHeader('HTTP_X_FORWARDED_HOST'))) {
			$xForwardedHost = $request->getHeader('HTTP_X_FORWARDED_HOST');
			if (isset($xForwardedHost[$xForwardedForRealIpKey])) {
				$remoteHost = trim($xForwardedHost[$xForwardedForRealIpKey]);
				$url->setHost($remoteHost);
			}
		}

		return [$remoteAddr, $remoteHost];
	}

	private function setAuthorization(Url $url, string $user): void
	{
		$pass = '';
		if (str_contains($user, ':')) {
			[$user, $pass] = explode(':', $user, 2);
		}

		$url->setUser($user);
		$url->setPassword($pass);
	}

	/**
	 * @return array<string, string>
	 */
	private function getHeaders(ServerRequestInterface $request): array
	{
		return array_map(
			static fn(array $header) => implode("\n", $header),
			$request->getHeaders()
		);
	}

	/**
	 * @return FileUpload[]
	 */
	private function getUploadedFiles(ServerRequestInterface $request): array
	{
		return array_map(static fn(UploadedFileInterface $file) => new FileUpload([
			'name' => $file->getClientFilename(),
			'size' => $file->getSize(),
			'error' => $file->getError(),
			'tmpName' => $file->getStream()->getMetadata('uri'),
		]), $request->getUploadedFiles());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getPost(ServerRequestInterface $request): array
	{
		return (array) $request->getParsedBody();
	}

	private function createUrl(ServerRequestInterface $request): Url
	{
		$url = new Url;
		$uri = $request->getUri();

		$url->setScheme($uri->getScheme());
		$url->setHost($uri->getHost());
		$url->setPort($uri->getPort());
		$url->setPath($uri->getPath());
		$url->setQuery($uri->getQuery());

		$this->setAuthorization($url, $uri->getUserInfo());

		return $url;
	}
}
