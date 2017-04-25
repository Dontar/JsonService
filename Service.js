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
            xhr.onreadystatechange = function () {
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
            var service = params.shift(), method = params.shift(), cb = typeof params[params.length - 1] == 'function' ? params.pop() : function () { }, id = _this.i++, callback = function (result, error) {
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
