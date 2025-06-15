document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('#manual-import-section button');
    const logArea = document.getElementById('import-log');

    const appendLog = (text) => {
        logArea.textContent += text + '\n';
        logArea.scrollTop = logArea.scrollHeight;
    };

    buttons.forEach(button => {
        button.addEventListener('click', function () {
            const type = this.getAttribute('data-type');
            appendLog(Iniciando importação de ${type}...);

            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'art_image_manual_import',
                    type: type,
                    _ajax_nonce: art_image_ajax.nonce
                })
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    appendLog(response.data.message);
                } else {
                    appendLog('Erro: ' + (response.data?.message || 'Desconhecido'));
                }
            })
            .catch(error => {
                appendLog('Erro na requisição: ' + error.message);
            });
        });
    });
});