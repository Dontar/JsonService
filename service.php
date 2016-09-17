<?php

class JsonService {

    private $servicesPath;

    function __construct($servicesPath = null) {

        ini_set("html_errors", 0);
        date_default_timezone_set('Europe/Berlin');

        $this->servicesPath = !empty($servicesPath)?$servicesPath:dirname(__FILE__);
    }

    private function handlerServiceRequest() {
        $response = array ();
        $input = json_decode(file_get_contents("php://input"), true);
        $isMulty = is_array($input);
        $input = ! is_array($input)?array ( $input ):$input;

        foreach ( $input as $calls ) {
            try {
                require_once $this->servicesPath . DIRECTORY_SEPARATOR . $calls['service'] . ".php";
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
                $service = substr($file, 0, $p);
                try {
                    require_once $this->servicesPath . DIRECTORY_SEPARATOR . $file;
                }
                catch ( Exception $e ) {
                    continue;
                }
                if (class_exists($service)) {
                    try {
                        $class = new ReflectionClass($service);
                        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
                        $ms = array ();
                        foreach ( $methods as $method ) {
                            /** @var ReflectionMethod $method */
                            if ($method->getName() == "__construct") continue;
                            $m = $method->getName();
                            $ms[] = "\t$m: function() {var params = Array.prototype.slice.call(arguments), cb = params.pop();srvc.queRequest(\"$service\", \"$m\", params, cb);}";
                        }
                        if (count($ms) > 0) {
                            $ms = implode(",\n", $ms);
                            $script .= "var $service = {\n$ms\n}\n";

                        }
                    } catch ( Exception $e ) {
                        continue;
                    }
                }
            }
        }
        return $this->generateJs().$script;
    }

    private function generateJs() {
        $requestUri = $_SERVER['REQUEST_URI'];
        return <<<OUT
var Binder;
(function (Binder) {
    function inputSetter(el) {
        switch (el.type) {
            case "month":
            case "time":
            case "week":
            case "date":
            case "datetime":
            case "datetime-local":
                return el.valueAsDate;
            case "color":
            case "email":
            case "hidden":
            case "password":
            case "tel":
            case "text":
            case "url":
            case "image":
                return el.value;
            case "checkbox":
            case "radio":
                return el.checked;
            case "number":
            case "range":
                return el.valueAsNumber;
            case "file":
                return el.files;
            case "button":
            case "reset":
            case "submit":
            default:
                return null;
        }
    }
    function inputGetter(val, el) {
        switch (el.type) {
            case "month":
            case "time":
            case "week":
            case "date":
            case "datetime":
            case "datetime-local":
                el.valueAsDate = val;
            case "color":
            case "email":
            case "hidden":
            case "password":
            case "tel":
            case "text":
            case "url":
            case "image":
                el.value = val;
            case "checkbox":
            case "radio":
                el.checked = val || false;
            case "number":
            case "range":
                el.valueAsNumber = val;
            case "file":
            case "button":
            case "reset":
            case "submit":
            default:
                break;
        }
    }
    function selectSetter(val, el) {
        var len = el.options.length;
        for (var i = 0; i < len; i++) {
            var op = el.options[i];
            if (val == op.value) {
                op.selected = true;
                break;
            }
        }
    }
    function selectGetter(el) {
        if (el.selectedIndex > -1) {
            return el.options[el.selectedIndex].value;
        }
        return null;
    }
    Binder.assign = Object.assign;
    function toModel(form) {
        var model = {
            assign: function (values) {
                Object.assign(this, values);
            }
        };
        var fields = form.querySelectorAll("input, select");
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].name) {
                Object.defineProperty(model, fields[i].name, {
                    enumerable: true,
                    get: ((fields[i] instanceof HTMLInputElement) ? inputGetter : selectGetter).bind(model, fields[i]),
                    set: ((fields[i] instanceof HTMLInputElement) ? inputSetter : selectSetter).bind(model, fields[i])
                });
            }
        }
        return model;
    }
    Binder.toModel = toModel;
})(Binder || (Binder = {}));

var srvc = {
    q: {},
    i: 0,
    sendRequests: function () {
        if (Object.keys(this.q).length > 0) {
            var q = this.q;
            this.q = {};
            var xhr = new XMLHttpRequest();
            xhr.onreadystatechange = function () {
                if (xhr.readyState == 4) {
                    if (xhr.status == 200) {
                        var content;
                        if (xhr.responseType == "json") {
                            content = xhr.response;
                        } else {
                            content = JSON.parse(xhr.responseText);
                        }
                        content.forEach(function (item) {
                            var t = q[item.id];
                            if (item.error) {
                                t.cb(undefined, item.error);
                            } else {
                                t.cb(item.result);
                            }
                        });
                    } else {
                        var error = {
                            message: xhr.responseText
                        }
                        Object.keys(q).forEach(function(t) {
                            q[t].cb(undefined, error);
                        });
                    }
                }
            }
            xhr.open("POST", "$requestUri", true);
            xhr.setRequestHeader("Content-type", "application/json");
            xhr.send(JSON.stringify(Object.keys(q).map(function (item) { return q[item].data; })));
        }
    },
    queRequest: function (service, method, params, cb) {
        var id = this.i++;
        this.q[id] = {
            id: id,
            data: {
                id: id,
                service: service,
                method: method,
                arguments: params
            },
            cb: cb
        };
    }
}
setInterval(srvc.sendRequests.bind(srvc), 20);

OUT;
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
