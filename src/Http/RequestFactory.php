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
	private ?ServerRequestInterface $serverRequest = null;

	/** @param string[] $proxies */
	public function setProxy(array $proxies): void
	{
		$this->proxies = $proxies;
	}

	public function setServerRequest(ServerRequestInterface $request): void
	{
		$this->serverRequest = $request;
	}

	public function getServerRequest(): ServerRequestInterface
	{
		if (!isset($this->serverRequest)) {
			throw new \RuntimeException('ServerRequest not set.');
		}
		return $this->serverRequest;
	}

	public function getRequest(ServerRequestInterface $request = null): Request
	{
		if (null !== $request) {
			$this->setServerRequest($request);
		}

		$url = $this->createUrl();

		[$remoteAddr, $remoteHost] = $this->resolveClientAttributes($url);

		return new Request(
			new UrlScript($url, $this->getScriptPath($url)),
			$this->getPost(),
			$this->getUploadedFiles(),
			$this->getCookies(),
			$this->getHeaders(),
			$this->getMethod(),
			$remoteAddr,
			$remoteHost,
			fn(): string => $this->getRequestBody()
		);
	}

	private function getScriptPath(Url $url): string
	{
		$path = $url->getPath();
		$lpath = strtolower($path);
		$script = strtolower($this->getServerRequest()->getServerParams()['SCRIPT_NAME'] ?? '');
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
	private function resolveClientAttributes(Url $url): array
	{
		$request = $this->getServerRequest();
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

	private function getMethod(): string
	{
		return $this->getServerRequest()->getMethod();
	}

	/**
	 * @return array<string, string>
	 */
	private function getCookies(): array
	{
		return $this->getServerRequest()->getCookieParams();
	}

	/**
	 * @return array<string, string>
	 */
	private function getHeaders(): array
	{
		return array_map(
			static fn(array $header) => implode("\n", $header),
			$this->getServerRequest()->getHeaders()
		);
	}

	/**
	 * @return FileUpload[]
	 */
	private function getUploadedFiles(): array
	{
		return array_map(static fn(UploadedFileInterface $file) => new FileUpload([
			'name' => $file->getClientFilename(),
			'size' => $file->getSize(),
			'error' => $file->getError(),
			'tmpName' => $file->getStream()->getMetadata('uri'),
		]), $this->getServerRequest()->getUploadedFiles());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getPost(): array
	{
		return (array) $this->getServerRequest()->getParsedBody();
	}

	private function getRequestBody(): string
	{
		return (string) $this->getServerRequest()->getBody();
	}

	private function createUrl(): Url
	{
		$url = new Url;
		$uri = $this->getServerRequest()->getUri();

		$url->setScheme($uri->getScheme());
		$url->setHost($uri->getHost());
		$url->setPort($uri->getPort());
		$url->setPath($uri->getPath());
		$url->setQuery($uri->getQuery());

		$this->setAuthorization($url, $uri->getUserInfo());

		return $url;
	}
}
