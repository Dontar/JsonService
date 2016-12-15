declare var _requestUri: string;

interface IPacket {
	id: number;
	service: string;
	method: string;
	arguments: any[];
}

interface IPacketResult {
	id: number;
	result?: any;
	error?: any
}

interface IPacketQueue {
	id: number;
	data: IPacket;
	cb: Function;
}

class Service {
	q: { [id: number]: IPacketQueue } = {};
	i: number = 0;

	constructor() {
		setInterval(this.processQueue.bind(this), 30);
	}

	processQueue() {
		if (Object.keys(this.q).length > 0) {
			var q = this.q;
			this.q = {};

			this.sendRequests(JSON.stringify(Object.keys(q).map(function (item) { return q[item].data; }))).then((content) => {
				content.forEach(function (item) {
					var t = q[item.id];
					if (item.error) {
						t.cb(undefined, item.error);
					} else {
						t.cb(item.result);
					}
				});
			}).catch((error) => {
				Object.keys(q).forEach(function (t) {
					q[t].cb(undefined, error);
				});
			});
		}
	}

	sendRequests(data: string) {
		return new Promise<IPacketResult[]>((resolve, reject) => {
			var xhr: XMLHttpRequest = new XMLHttpRequest();
			xhr.open("POST", _requestUri, true);
			xhr.setRequestHeader("Content-type", "application/json");
			xhr.onreadystatechange = () => {
				if (xhr.readyState == 4) {
					if (xhr.status == 200) {
						var content;
						if (xhr.responseType == "json") {
							content = xhr.response;
						} else {
							content = JSON.parse(xhr.responseText);
						}
						resolve(content);
					} else {
						reject({
							message: xhr.responseText
						});
					}
				}
			}
			xhr.send(data);
		});
	}

	queRequest(...params: any[]) {
		return new Promise((resolve, reject) => {
			var
				service = params.shift(),
				method = params.shift(),
				cb = typeof params[params.length - 1] == 'function' ? params.pop() : function () { },
				id = this.i++,
				callback = (result, error) => {
					error ? reject(error) : resolve(result), cb(result, error);
				};

			this.q[id] = {
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
	}
}
var srvc = new Service();