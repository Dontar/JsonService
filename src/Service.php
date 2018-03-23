<?php

namespace JSON;

class Service
{

	private $servicesPath;

	function __construct($servicesPath)
	{
		$this->servicesPath = $servicesPath;
	}

	static public function process($servicesPath)
	{
		$service = new Service($servicesPath);

		if ($_SERVER['REQUEST_METHOD'] == "POST") {
			$service->handlerServiceRequest();
		} else {
			$service->handlerScriptRequest();
		}
	}

	private function handlerScriptRequest()
	{
		header("Content-Type: text/javascript");
		$out = new \SplFileObject("php://output");
		$out->fwrite("'use strict';\n");
		$out->fwrite(sprintf("var _requestUri = '%s';\n", $_SERVER['REQUEST_URI']));
		$out->fwrite($this->exportJs());

		/** @var \DirectoryIterator $fileInfo*/
		foreach (new \DirectoryIterator($this->servicesPath) as $fileInfo) {
			if ($fileInfo->getExtension() == "php") {
				$service = $fileInfo->getBasename(".php");
				$out->fwrite("var $service = {\n");

				/** @var \ReflectionMethod $method */
				foreach ((new \ReflectionClass($service))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
					if (strpos($method->getDocComment(), "@export") !== false) {
						$out->fwrite(sprintf("\t%s: srvc.queRequest.bind(srvc, '%s', '%s'),\n", $mn = $method->getName(), $service, $mn));
					}
				}
				$out->fwrite("};\n");
			}
		}
	}

	private function handlerServiceRequest()
	{
		$requests = @json_decode(file_get_contents("php://input"));
		// If we have REST request
		if (empty($_SERVER['HTTP_X_JSONRPC'])) {
			$path = explode("/", trim($_SERVER['REQUEST_URI'], "/"));
			list($service, $method) = array_slice($path, -2);

			$arguments = [];
			if (!empty($_SERVER['QUERY_STRING'])) {
				parse_str($_SERVER['QUERY_STRING'], $arguments);
			} else {
				$arguments = $requests;
			}

			$requests = [(object)[
				"id" => -1,
				"service" => $service,
				"method" => $method,
				"arguments" => $arguments ? array_values($arguments) : []
			]];
		} else {
			$requests = is_object($requests) ? [$requests] : $requests;
		}

		$response = [];
		foreach ($requests as $call) {
			/** @var Request $call */
			try {
				$response[] = $this->handleError(function () use (&$call) {
					$self = new $call->{"service"}();
					$result = call_user_func_array([$self, $call->method], $call->arguments);
					return (object)["result" => $result, "id" => $call->id];
				});
			} catch (Exception $e) {
				$response[] = (object)[
					"id" => $call->id,
					"error" => array(
						"message" => $e->getMessage(),
						"code" => $e->getCode(),
						"trace" => $e->getTrace()
					)
				];
			}
		}
		if ($post) {
			$this->postResponce($response);
		} else {
			$this->processResponces($response);
		}
	}

	private function postResponce($response)
	{
		header("Content-Type: application/json; charset=utf8");
		$out = new \SplFileObject("php://output");

		$data = $response[0]->result;

		if ($data instanceof \Generator) {
			try {
				if ($data->valid()) {
					$out->fwrite('[' . json_encode($data->current()));
					$data->next();
					while ($data->valid()) {
						$out->fwrite(',' . json_encode($data->current()));
						$data->next();
					}
					$out->fwrite(']');
				} else {
					$out->fwrite('[]');
				}
			} catch (\ErrorException $e) {
				$out->fwrite(json_encode([
					"error" => [
						"message" => $e->getMessage(),
						"code" => $e->getCode(),
						"trace" => $e->getTrace()
					]
				]));
			}
		} else {
			$out->fwrite(json_encode($data));
		}

	}

	/**
	 * Undocumented function
	 *
	 * @param callable $callback
	 * @return mixed
	 */
	protected function handleError($callback)
	{
		$error = [];
		$oldHandler = set_error_handler(function ($err_severity, $err_msg, $err_file, $err_line) use (&$error) {
			if (0 === error_reporting()) {
				return false;
			}
			$error['type'] = $err_severity;
			$error['message'] = $err_msg;
			$error['file'] = $err_file;
			$error['line'] = $err_line;
			return true;
		});

		$result = $callback();

		set_error_handler($oldHandler);
		if (!empty($error)) {
			// $msg = "%s\nSQL: %s\nParams: %s\n";
			throw new \ErrorException(
				// sprintf($msg, $err['message'], $query, json_encode($params)),
				$error['message'],
				$error['type'],
				$error['type'],
				$error['file'],
				$error['line']
			);
		}
		return $result;
	}

	/**
	 * Undocumented function
	 *
	 * @param Response[] $responses
	 * @return void
	 */
	private function processResponces($responses)
	{
		header("Content-Type: application/json; charset=utf8");
		$out = new \SplFileObject("php://output");
		$out->fwrite("[");

		$last = count($responses) - 1;
		foreach ($responses as $key => $response) {
			$data = $response->result;

			if ($data instanceof \Generator) {
				try {
					if ($data->valid()) {
						$out->fwrite(sprintf('{"id": %s, "result": [', $response->id) . json_encode($data->current()));
						$data->next();
						while ($data->valid()) {
							$out->fwrite(',' . json_encode($data->current()));
							$data->next();
						}
						$out->fwrite(']}');
					}
				} catch (\ErrorException $e) {
					$out->fwrite(json_encode([
						"id" => $response->id,
						"error" => [
							"message" => $e->getMessage(),
							"code" => $e->getCode(),
							"trace" => $e->getTrace()
						]
					]));
				}
			} else {
				$out->fwrite(json_encode($response));
			}
			if ($key < $last) $out->fwrite(",");
		}

		$out->fwrite("]");
	}

	private function exportJs()
	{
		return <<<JS
var Service = (function () {
	function Service() {
		this.q = {};
		this.i = 0;
		setInterval(this.processQueue.bind(this), 30);
	}
	Service.prototype.processQueue = function () {
		if (Object.keys(this.q).length > 0) {
			var q = this.q;
			this.q = {};
			this.sendRequests(JSON.stringify(Object.keys(q).map(function (item) { return q[item].data; }))).then(function (content) {
				content.forEach(function (item) {
					var t = q[item.id];
					if (item.error) {
						t.cb(undefined, item.error);
					}
					else {
						t.cb(item.result);
					}
				});
			}, function (error) {
				Object.keys(q).forEach(function (t) {
					q[t].cb(undefined, error);
				});
			});
		}
	};
	Service.prototype.sendRequests = function (data) {
		return new Promise(function (resolve, reject) {
			var xhr = new XMLHttpRequest();
			xhr.open("POST", _requestUri, true);
			xhr.setRequestHeader("Content-type", "application/json");
			xhr.setRequestHeader("X-JSONRPC", "V2");
			xhr.onreadystatechange = function () {
				try {
					if (xhr.readyState == 4) {
						if (xhr.status == 200) {
							var content;
							if (xhr.responseType == "json") {
								content = xhr.response;
							}
							else {
								content = JSON.parse(xhr.responseText);
							}
							resolve(content);
						}
						else {
							reject({
								message: xhr.responseText
							});
						}
					}
				}
				catch (e) {
					reject(e);
				}
			};
			xhr.send(data);
		});
	};
	Service.prototype.queRequest = function () {
		var _this = this;
		var params = [];
		for (var _i = 0; _i < arguments.length; _i++) {
			params[_i] = arguments[_i];
		}
		return new Promise(function (resolve, reject) {
			var
				service = params.shift(),
				method = params.shift(),
				cb = typeof params[params.length - 1] == 'function' ? params.pop() : function () { },
				id = _this.i++,
				callback = function (result, error) {
					error ? reject(error) : resolve(result), cb(result, error);
				};
			_this.q[id] = {
				id: id,
				data: {
					id: id,
					service: service,
					method: method,
					arguments: params
				},
				cb: callback
			};
		});
	};
	return Service;
}());
var srvc = new Service();

JS;
	}
}
