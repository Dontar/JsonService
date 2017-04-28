<?php

class JsonService {

	private $servicesPath;

	function __construct($servicesPath = null) {

		ini_set("html_errors", 0);
		// date_default_timezone_set('Europe/Berlin');

		$this->servicesPath = !empty($servicesPath)?$servicesPath:dirname(__FILE__);
	}

	private function handlerServiceRequest() {
		$response = array ();
		$input = json_decode(file_get_contents("php://input"), true);
		$isMulty = is_array($input);
		$input = ! is_array($input)?array ( $input ):$input;

		foreach ( $input as $calls ) {
			try {
				// require_once $this->servicesPath . DIRECTORY_SEPARATOR . $calls['service'] . ".php";
				$self = new $calls['service']();
				$result = call_user_func_array(array ( $self, $calls['method'] ), $calls['arguments']);
				$response[] = array ( "result" => $result, "id" => $calls['id'] );
			}
			catch ( Exception $e ) {
				$response[] = array (
					"id" => $calls['id'],
					"error" => array (
						"message" => $e->getMessage(),
						"code" => $e->getCode(),
						"trace" => $e->getTrace()
					)
				);
			}

		}

		return $isMulty?json_encode($response):json_encode($response[0]);
	}

	private function handlerScriptReuqest() {
		$script = "";
		$dir = scandir($this->servicesPath);
		foreach ( $dir as $file ) {
			if (($p = strpos($file, ".php")) !== false && $file != basename(__FILE__)) {
				$fullPath = $this->servicesPath . DIRECTORY_SEPARATOR . $file;
				$exportSignature = file_get_contents($fullPath, false, null, -1, 30);
				if (strpos($exportSignature, "@export") === false) continue;
				$service = substr($file, 0, $p);
				// try {
				// 	require_once $fullPath;
				// }
				// catch ( Exception $e ) {
				// 	continue;
				// }
				// if (class_exists($service)) {
					try {
						$class = new ReflectionClass($service);
						$methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
						$ms = array ();
						foreach ( $methods as $method ) {
							/** @var ReflectionMethod $method */
							if ($method->getName() == "__construct") continue;
							$m = $method->getName();
							$ms[] = "\t$m: srvc.queRequest.bind(srvc, \"$service\", \"$m\")";
						}
						if (count($ms) > 0) {
							$ms = implode(",\n", $ms);
							$script .= "var $service = {\n$ms\n}\n";

						}
					} catch ( Exception $e ) {
						continue;
					}
				// }
			}
		}
		return $this->generateJs().$script;
	}

	private function generateJs() {
		$requestUri = $_SERVER['REQUEST_URI'];
		return "var _requestUri = \"$requestUri\";\n".file_get_contents(dirname(__FILE__)."/Service.js");
	}

	public function dispatch() {
		if ($_SERVER['REQUEST_METHOD'] == "POST") {
			header("Content-Type: application/json");
			echo $this->handlerServiceRequest();
		}
		else {
			header("Content-Type: text/javascript");
			echo $this->handlerScriptReuqest();
		}
	}

}
