document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('#manual-import-section .import-buttons button');
    const logArea = document.getElementById('import-log');
    const progressSection = document.getElementById('import-progress');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const progressTime = document.getElementById('progress-time');
    const clearLogBtn = document.getElementById('clear-log');
    const cancelBtn = document.getElementById('cancel-import');

    let isImporting = false;
    let currentImportType = null;
    let startTime = null;

    const appendLog = (text) => {
        const timestamp = new Date().toLocaleTimeString();
        logArea.textContent += `[${timestamp}] ${text}\n`;
        logArea.scrollTop = logArea.scrollHeight;
    };

    const updateTimer = () => {
        if (!startTime) return;
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        const minutes = Math.floor(elapsed / 60);
        const seconds = elapsed % 60;
        progressTime.textContent = `Tempo: ${minutes}:${seconds.toString().padStart(2, '0')}`;
    };

    const setButtonState = (button, state) => {
        const indicator = button.querySelector('.status-indicator');
        const originalText = button.getAttribute('data-original-text');
        
        switch (state) {
            case 'idle':
                button.disabled = false;
                button.className = 'button' + (button.classList.contains('button-primary') ? ' button-primary' : '');
                button.innerHTML = `<span class="status-indicator idle"></span>${originalText}`;
                break;
            case 'running':
                button.disabled = true;
                button.className = 'button importing';
                button.innerHTML = `<span class="status-indicator running"></span>Importando...`;
                break;
            case 'success':
                indicator.className = 'status-indicator success';
                setTimeout(() => setButtonState(button, 'idle'), 3000);
                break;
            case 'error':
                indicator.className = 'status-indicator error';
                setTimeout(() => setButtonState(button, 'idle'), 5000);
                break;
        }
    };

    const updateProgress = (current, total, text = '') => {
        if (total > 0) {
            const percentage = Math.round((current / total) * 100);
            progressFill.style.width = percentage + '%';
            progressFill.textContent = percentage + '%';
            progressText.textContent = text || `${current} de ${total} processados`;
        } else {
            progressFill.style.width = '0%';
            progressFill.textContent = '0%';
            progressText.textContent = text || 'Preparando...';
        }
    };

    const resetState = () => {
        buttons.forEach(btn => setButtonState(btn, 'idle'));
        progressSection.classList.remove('active');
        cancelBtn.style.display = 'none';
        isImporting = false;
        currentImportType = null;
        startTime = null;
    };

    const startImport = (button, type) => {
        if (isImporting) {
            appendLog('Importação já em andamento. Aguarde...');
            return;
        }

        isImporting = true;
        currentImportType = type;
        startTime = Date.now();
        
        setButtonState(button, 'running');
        progressSection.classList.add('active');
        updateProgress(0, 0, 'Iniciando...');
        
        if (type === 'products') {
            cancelBtn.style.display = 'inline-block';
        }

        const timer = setInterval(updateTimer, 1000);
        
        const cleanup = (success = true) => {
            clearInterval(timer);
            setButtonState(button, success ? 'success' : 'error');
            if (type !== 'products' || !success) {
                setTimeout(resetState, 2000);
            }
        };

        return { cleanup, timer };
    };

    // Salva textos originais dos botões
    buttons.forEach(button => {
        const text = button.textContent.replace(/^\s*$/, '').trim();
        button.setAttribute('data-original-text', text);
    });

    // Handler para importação simples (categorias, subcategorias, artistas)
    const handleSimpleImport = (button, type) => {
        const { cleanup } = startImport(button, type);
        appendLog(`Iniciando importação de ${type}...`);

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
                if (Array.isArray(response.data)) {
                    response.data.forEach(msg => appendLog(msg));
                    updateProgress(1, 1, `${response.data.length} itens processados`);
                } else {
                    appendLog(response.data.message || 'Importação concluída');
                    updateProgress(1, 1, 'Concluído');
                }
                cleanup(true);
            } else {
                appendLog('Erro: ' + (response.data?.message || 'Desconhecido'));
                cleanup(false);
            }
        })
        .catch(error => {
            appendLog('Erro na requisição: ' + error.message);
            cleanup(false);
        });
    };

    // Handler para importação em lotes (produtos)
    const handleBatchImport = (button, type) => {
        const { cleanup } = startImport(button, type);
        appendLog(`Iniciando importação de ${type}...`);
        appendLog('Preparando fila de importação...');

        let currentPage = 1;

        const processBatch = (page) => {
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'art_image_batch_import',
                    type: type,
                    page: page,
                    _ajax_nonce: art_image_ajax.nonce
                })
            })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    const data = response.data;
                    
                    // Log das mensagens do lote
                    if (Array.isArray(data.logs)) {
                        data.logs.forEach(msg => appendLog(msg));
                    }

                    // Atualiza progresso
                    if (data.current_total_processed && data.total_to_import) {
                        updateProgress(
                            data.current_total_processed, 
                            data.total_to_import,
                            `${data.current_total_processed}/${data.total_to_import} produtos`
                        );
                    }

                    // Verifica se há mais lotes
                    if (data.status === 'processing' && data.has_more) {
                        setTimeout(() => processBatch(data.next_page), 1000);
                    } else if (data.status === 'completed') {
                        appendLog('Importação de produtos concluída com sucesso!');
                        updateProgress(data.total_to_import, data.total_to_import, 'Concluído!');
                        cleanup(true);
                        setTimeout(resetState, 3000);
                    } else if (data.status === 'cancelled') {
                        appendLog('Importação cancelada.');
                        cleanup(false);
                        setTimeout(resetState, 2000);
                    } else {
                        appendLog('Importação finalizada com status: ' + data.status);
                        cleanup(data.status === 'completed');
                        setTimeout(resetState, 3000);
                    }
                } else {
                    appendLog('Erro no lote: ' + (response.data?.message || 'Desconhecido'));
                    cleanup(false);
                }
            })
            .catch(error => {
                appendLog('Erro na requisição do lote: ' + error.message);
                cleanup(false);
            });
        };

        processBatch(currentPage);
    };

    // Event listeners para botões de importação
    buttons.forEach(button => {
        button.addEventListener('click', function () {
            const type = this.getAttribute('data-type');
            
            if (type === 'products') {
                handleBatchImport(this, type);
            } else {
                handleSimpleImport(this, type);
            }
        });
    });

    // Event listener para limpar log
    clearLogBtn.addEventListener('click', function() {
        logArea.textContent = '';
        appendLog('Log limpo.');
    });

    // Event listener para cancelar importação
    cancelBtn.addEventListener('click', function() {
        if (isImporting && currentImportType === 'products') {
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    action: 'art_image_cancel_import',
                    _ajax_nonce: art_image_ajax.nonce
                })
            });
            appendLog('Solicitação de cancelamento enviada...');
            this.disabled = true;
        }
    });

    // Inicializa log com mensagem de boas-vindas
    appendLog('Sistema de importação Art Image pronto.');
    appendLog('Selecione uma opção acima para começar.');
});