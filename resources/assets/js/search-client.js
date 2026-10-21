// Ricerca degli editor del backend (documenti di magazzino, ordini d'ufficio):
// messaggio sotto la casella, richieste al server e «conta solo l'ultima».
window.wiSearchClient = window.wiSearchClient || function (options) {
    var message = options.message;
    var url = options.url;
    var place = options.place;
    var seq = 0;

    function say(text, tone) {
        message.textContent = text || '';
        message.className = 'small mt-1 ' + (tone === 'error' ? 'text-danger' : 'text-body-secondary');
    }

    function request(params, done, fail) {
        var where = place ? '&location=' + encodeURIComponent(place.value) : '';

        window.fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + params + where, {
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        }).then(done, fail);
    }

    // La ricerca per nome: conta solo l'ultima domanda.
    function lookup(params, done) {
        var mine = ++seq;

        request(params, function (data) {
            if (mine === seq) {
                done(data);
            }
        }, function () {
            if (mine === seq) {
                options.close();
                say(options.down, 'error');
            }
        });
    }

    // Le risposte già in viaggio non contano più.
    function cancel() {
        seq++;
    }

    return {say: say, request: request, lookup: lookup, cancel: cancel};
};
