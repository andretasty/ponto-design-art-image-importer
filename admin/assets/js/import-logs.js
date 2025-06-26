document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('#manual-import-section .import-buttons button');
    const logArea = document.getElementById('import-log');
    const progressSection = document.getElementById('import-progress');
    const progressFill = document.getElementById('progress-fill');
    const progressText = document.getElementById('progress-text');
    const progressTime = document.getElementById('progress-time');
    const clearLogBtn = document.getElementById('clear-log');
    const mainCancelBtn = document.getElementById('cancel-import'); // Renamed for clarity
    const activeImportsSectionElement = document.getElementById('active-imports-section');
    const activeImportsListElement = document.getElementById('active-imports-list');

    let isImporting = false;
    let currentImportType = null;
    let startTime = null;
    let activeImportPollInterval;
    let cancelFromListInProgress = false;


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
        const originalText = button.getAttribute('data-original-text') || button.textContent; // Fallback
        
        switch (state) {
            case 'idle':
                button.disabled = false;
                button.className = 'button' + (button.classList.contains('button-primary') ? ' button-primary' : '');
                if (indicator) {
                    button.innerHTML = `<span class="status-indicator idle"></span>${originalText}`;
                } else {
                    button.textContent = originalText;
                }
                break;
            case 'running':
                button.disabled = true;
                button.className = 'button importing';
                if (indicator) {
                    button.innerHTML = `<span class="status-indicator running"></span>Importando...`;
                } else {
                    button.textContent = 'Importando...';
                }
                break;
            case 'success':
                if (indicator) indicator.className = 'status-indicator success';
                setTimeout(() => setButtonState(button, 'idle'), 3000);
                break;
            case 'error':
                if (indicator) indicator.className = 'status-indicator error';
                setTimeout(() => setButtonState(button, 'idle'), 5000);
                break;
        }
    };

    const updateProgress = (current, total, text = '') => {
        if (progressFill && progressText) { // Check if elements exist
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
        }
    };
    
    const getTypeName = (typeKey) => {
        switch (typeKey) {
            case 'categories': return 'Categorias';
            case 'subcategories': return 'Subcategorias';
            case 'artists': return 'Artistas';
            case 'products': return 'Produtos';
            default: return typeKey || 'Desconhecido';
        }
    };

    const displayActiveImports = (activeImportType) => {
        if (!activeImportsListElement || !activeImportsSectionElement) return;
        
        // Limpa qualquer mensagem de cancelamento anterior
        clearTimeout(window.artImageDisplayTimeout);
        
        activeImportsListElement.innerHTML = ''; 

        if (activeImportType && activeImportType !== false && activeImportType !== '') {
            activeImportsSectionElement.style.display = 'block';
            const listItem = document.createElement('div');
            listItem.className = 'active-import-item';
            
            const typeName = getTypeName(activeImportType);
            const textNode = document.createTextNode(`Processo em andamento: ${typeName} `);
            listItem.appendChild(textNode);

            const cancelButtonFromList = document.createElement('button');
            cancelButtonFromList.className = 'button button-small button-cancel-active-list'; 
            cancelButtonFromList.textContent = 'Cancelar este processo';
            cancelButtonFromList.setAttribute('data-cancel-type', activeImportType);

            cancelButtonFromList.addEventListener('click', function() {
                if (cancelFromListInProgress) return;
                cancelFromListInProgress = true;

                const typeToCancel = this.getAttribute('data-cancel-type');
                let cancelAction = '';
                switch (typeToCancel) {
                    case 'categories': cancelAction = 'art_image_cancel_categories_import'; break;
                    case 'subcategories': cancelAction = 'art_image_cancel_subcategories_import'; break;
                    case 'artists': cancelAction = 'art_image_cancel_artists_import'; break;
                    case 'products': cancelAction = 'art_image_cancel_products_import'; break;
                    default:
                        appendLog('Erro: Tipo de cancelamento desconhecido para processo ativo: ' + typeToCancel);
                        cancelFromListInProgress = false;
                        return;
                }

                appendLog(`Enviando solicitação para cancelar importação ativa de ${getTypeName(typeToCancel)}...`);
                
                // Feedback imediato - remove o botão e mostra status de cancelamento
                this.style.display = 'none';
                activeImportsListElement.innerHTML = `<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; color: #856404;">
                    <strong>Cancelando ${getTypeName(typeToCancel)}...</strong><br>
                    <small>Aguarde enquanto o processo é interrompido.</small>
                </div>`;

                // Se este processo é o mesmo que está rodando localmente, cancelar também
                if (isImporting && currentImportType === typeToCancel) {
                    isImporting = false;
                }

                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: cancelAction, _ajax_nonce: art_image_ajax.nonce })
                })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        appendLog(`Cancelamento de ${getTypeName(typeToCancel)} processado com sucesso.`);
                        
                        // Atualiza a interface imediatamente - mostra sucesso e depois remove
                        activeImportsListElement.innerHTML = `<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724;">
                            <strong>✓ ${getTypeName(typeToCancel)} cancelado com sucesso!</strong><br>
                            <small>O processo foi interrompido.</small>
                        </div>`;
                        
                        // Após 2 segundos, volta ao estado padrão
                        window.artImageDisplayTimeout = setTimeout(() => {
                            if (activeImportsListElement) {
                                activeImportsListElement.textContent = 'Nenhum processo em andamento no momento.';
                            }
                        }, 2000);
                        
                        // Se era o processo local, resetar o estado
                        if (currentImportType === typeToCancel) {
                            resetState();
                        } else {
                            // Confirma o estado atualizado após um pequeno delay
                            setTimeout(fetchActiveImports, 500);
                        }
                    } else {
                        appendLog(`Erro ao processar cancelamento para ${getTypeName(typeToCancel)}: ${response.data?.message || 'Erro desconhecido'}`);
                        
                        // Mostra erro na interface
                        activeImportsListElement.innerHTML = `<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">
                            <strong>✗ Erro ao cancelar ${getTypeName(typeToCancel)}</strong><br>
                            <small>${response.data?.message || 'Erro desconhecido'}. Verifique os logs.</small>
                        </div>`;
                        
                        // Mesmo com erro, se era processo local, resetar
                        if (currentImportType === typeToCancel) {
                            resetState();
                        } else {
                            // Após 3 segundos, verifica o estado real
                            window.artImageDisplayTimeout = setTimeout(() => {
                                fetchActiveImports();
                            }, 3000);
                        }
                    }
                })
                .catch(error => {
                    appendLog(`Erro na requisição de cancelamento para ${getTypeName(typeToCancel)}: ${error.message}`);
                    
                    // Como houve erro de conexão, mostra mensagem apropriada
                    activeImportsListElement.innerHTML = `<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; color: #721c24;">
                        <strong>✗ Erro de conexão ao cancelar ${getTypeName(typeToCancel)}</strong><br>
                        <small>Falha na comunicação com o servidor. O processo pode ter sido interrompido.</small>
                    </div>`;
                    
                    // Mesmo com erro, se era processo local, resetar
                    if (currentImportType === typeToCancel) {
                        resetState();
                    } else {
                        // Verifica o estado real após um delay e restaura interface se necessário
                        window.artImageDisplayTimeout = setTimeout(() => {
                            fetchActiveImports();
                            // Se não houver processos ativos, volta ao estado padrão
                            setTimeout(() => {
                                if (activeImportsListElement && activeImportsListElement.innerHTML.includes('Erro de conexão')) {
                                    activeImportsListElement.textContent = 'Nenhum processo em andamento no momento.';
                                }
                            }, 2000);
                        }, 1000);
                    }
                })
                .finally(() => {
                    setTimeout(() => { cancelFromListInProgress = false; }, 2000);
                });
            });

            listItem.appendChild(cancelButtonFromList);
            activeImportsListElement.appendChild(listItem);
        } else {
            activeImportsListElement.textContent = 'Nenhum processo em andamento no momento.';
        }
    };

    const fetchActiveImports = () => {
        if (!art_image_ajax || !art_image_ajax.nonce || cancelFromListInProgress) {
            return; 
        }
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'art_image_get_active_imports', _ajax_nonce: art_image_ajax.nonce })
        })
        .then(res => res.json())
        .then(response => {
            if (response.success && typeof response.data.active_import_type !== 'undefined') {
                if (!isImporting) { 
                    displayActiveImports(response.data.active_import_type);
                } else if (isImporting && !response.data.active_import_type) {
                    // A importação pode ter acabado de terminar (o servidor limpou o lock, mas o cliente ainda não fez o resetState que tem um delay).
                    // Para evitar falsos positivos, esperamos um pouco mais que o delay do resetState antes de exibir o aviso.
                    setTimeout(() => {
                        // Se, após a espera, a aba AINDA acha que está importando, então é um estado obsoleto real.
                        if (isImporting) {
                            appendLog("Aviso: O servidor não reporta nenhum processo ativo, mas esta aba indica uma importação em andamento. Verificando o estado local.");
                        }
                    }, 4000); // O resetState tem um delay de 3s no sucesso. 4s é seguro.
                }
            }
        })
        .catch(error => {
            // console.error('Erro ao buscar processos ativos:', error);
        });
    };
    
    const resetState = () => {
        buttons.forEach(btn => setButtonState(btn, 'idle'));
        if(progressSection) progressSection.classList.remove('active');
        if(progressSection) progressSection.style.display = 'block'; 
        if(mainCancelBtn) mainCancelBtn.style.display = 'none';
        isImporting = false; 
        currentImportType = null;
        startTime = null;
        if (typeof fetchActiveImports === "function") {
            fetchActiveImports();
        } else if (typeof displayActiveImports === "function") {
            displayActiveImports(null);
        }
    };

    // Função para verificar e reconectar a importação em andamento
    const checkAndReconnectImport = () => {
        if (!art_image_ajax || !art_image_ajax.nonce) return;
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 
                action: 'art_image_check_import_state', 
                _ajax_nonce: art_image_ajax.nonce 
            })
        })
        .then(res => res.json())
        .then(response => {
            if (response.success && response.data.lock) {
                const importType = response.data.lock;
                const processed = response.data.processed || 0;
                const total = response.data.total || 0;
                
                appendLog(`Reconectando à importação em andamento: ${getTypeName(importType)}`);
                appendLog(`Progresso atual: ${processed}/${total} itens processados`);
                
                // Reconecta à importação
                isImporting = true;
                currentImportType = importType;
                startTime = Date.now() - (30000); // Simula 30 segundos já decorridos
                
                // Atualiza interface
                const button = document.querySelector(`[data-type="${importType}"]`);
                if (button) setButtonState(button, 'running');
                
                if(progressSection) {
                    progressSection.style.display = 'block';
                    progressSection.classList.add('active');
                }
                if(mainCancelBtn) mainCancelBtn.style.display = 'inline-block';
                
                updateProgress(processed, total, `${processed}/${total} ${getTypeName(importType)}`);
                
                // Retoma o polling
                resumeImportPolling(importType);
            }
        })
        .catch(error => {
            // Silencioso - pode não haver importação em andamento
        });
    };

    // Função para retomar o polling da importação
    const resumeImportPolling = (importType) => {
        let currentPage = Math.floor((parseInt(get_processed()) || 0) / 5) + 1; // Estima página atual
        
        const pollImport = () => {
            if (!isImporting || currentImportType !== importType) return;
            
            let ajaxAction = '';
            switch (importType) {
                case 'categories': ajaxAction = 'art_image_import_categories'; break;
                case 'subcategories': ajaxAction = 'art_image_import_subcategories'; break;
                case 'artists': ajaxAction = 'art_image_import_artists'; break;
                case 'products': ajaxAction = 'art_image_import_products'; break;
                default: return;
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ 
                    action: ajaxAction, 
                    page: currentPage, 
                    _ajax_nonce: art_image_ajax.nonce 
                })
            })
            .then(res => res.json())
            .then(response => {
                if (!isImporting || currentImportType !== importType) return;
                
                if (response.success) {
                    const data = response.data;
                    if (Array.isArray(data.logs)) {
                        data.logs.forEach(msg => appendLog(msg));
                    }
                    if (typeof data.current_total_processed !== 'undefined' && typeof data.total_to_import !== 'undefined') {
                        updateProgress(data.current_total_processed, data.total_to_import, `${data.current_total_processed}/${data.total_to_import} ${getTypeName(importType)}`);
                    }
                    
                    if (data.status === 'processing' && data.has_more) {
                        currentPage = data.next_page;
                        setTimeout(pollImport, 500);
                    } else if (data.status === 'completed') {
                        appendLog(`Importação de ${getTypeName(importType)} concluída!`);
                        resetState();
                    } else if (data.status === 'cancelled') {
                        appendLog(`Importação de ${getTypeName(importType)} cancelada.`);
                        resetState();
                    } else if (data.status === 'error') {
                        appendLog(`Erro na importação de ${getTypeName(importType)}.`);
                        resetState();
                    }
                } else {
                    appendLog(`Erro ao reconectar importação: ${response.data?.message || 'Erro desconhecido'}`);
                    resetState();
                }
            })
            .catch(error => {
                appendLog(`Erro na conexão: ${error.message}`);
                resetState();
            });
        };
        
        // Inicia o polling
        setTimeout(pollImport, 1000);
    };

    // Função auxiliar para obter valor processado
    const get_processed = () => {
        const text = progressText ? progressText.textContent : '';
        const match = text.match(/(\d+)\/(\d+)/);
        return match ? match[1] : '0';
    };

    const startImport = (button, type) => {
        if (isImporting) {
            appendLog('Importação já em andamento. Aguarde...');
            return null; // Return null to indicate import did not start
        }

        isImporting = true; 
        currentImportType = type;
        startTime = Date.now();
        
        setButtonState(button, 'running');
        // Always show progress section now
        if(progressSection) progressSection.style.display = 'block';
        if(progressSection) progressSection.classList.add('active');
        updateProgress(0, 0, 'Iniciando...');
        
        if(mainCancelBtn) mainCancelBtn.style.display = 'inline-block';
        if(mainCancelBtn) mainCancelBtn.disabled = false;

        if (typeof displayActiveImports === "function") {
            displayActiveImports(currentImportType);
        }

        const timer = setInterval(updateTimer, 1000);
        
        const cleanup = (success = true, responseData = null) => {
            clearInterval(timer);
            setButtonState(button, success ? 'success' : 'error');
            
            let shouldReset = true;
            if (responseData && responseData.status === 'processing' && responseData.has_more) {
                shouldReset = false; 
            }

            if (shouldReset) {
                setTimeout(resetState, success ? 3000 : 2000);
            }
        };
        return { cleanup, timer };
    };

    buttons.forEach(button => {
        const text = button.textContent.replace(/^\s*$/, '').trim();
        button.setAttribute('data-original-text', text);
    });

    const handleGenericBatchImport = (button, type) => {
        const importState = startImport(button, type);
        if (!importState) return; 
        const { cleanup } = importState;

        appendLog(`Iniciando importação de ${getTypeName(type)}...`);
        if (type !== 'products') { 
            appendLog('Preparando fila de importação...');
        }

        let currentPage = 1;
        let ajaxAction = '';
        
        switch (type) {
            case 'categories': ajaxAction = 'art_image_import_categories'; break;
            case 'subcategories': ajaxAction = 'art_image_import_subcategories'; break;
            case 'artists': ajaxAction = 'art_image_import_artists'; break;
            case 'products': ajaxAction = 'art_image_import_products'; break;
            default:
                appendLog('Tipo de importação desconhecido: ' + type);
                cleanup(false);
                return;
        }

        // Defina processBatch ANTES de prepareQueueBatch
        const processBatch = (page) => {
            if (!isImporting || currentImportType !== type) {
                appendLog(`Importação de ${getTypeName(type)} interrompida externamente ou sobreposta.`);
                return;
            }
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: ajaxAction, page: page, _ajax_nonce: art_image_ajax.nonce })
            })
            .then(res => res.json())
            .then(response => {
                if (!isImporting || currentImportType !== type) { 
                    appendLog(`Importação de ${getTypeName(type)} interrompida (pós-fetch).`);
                    return; 
                }
                if (response.success) {
                    const data = response.data;
                    if (Array.isArray(data.logs)) {
                        data.logs.forEach(msg => appendLog(msg));
                    }
                    if (typeof data.current_total_processed !== 'undefined' && typeof data.total_to_import !== 'undefined') {
                        updateProgress( data.current_total_processed, data.total_to_import, `${data.current_total_processed}/${data.total_to_import} ${getTypeName(type)}`);
                    } else if (page === 1 && data.status !== 'processing' && data.status !== 'error' && data.status !== 'cancelled') {
                         updateProgress(0,0, data.logs && data.logs.length > 0 ? data.logs[data.logs.length-1] : 'Aguardando...');
                    }
                    if (data.status === 'processing' && data.has_more) {
                        setTimeout(() => processBatch(data.next_page), 500);
                    } else if (data.status === 'completed') {
                        let message = `Importação de ${getTypeName(type)} concluída com sucesso!`;
                        appendLog(message);
                        if (typeof data.total_to_import !== 'undefined' && data.total_to_import > 0) {
                           updateProgress(data.total_to_import, data.total_to_import, 'Concluído!');
                        } else if (typeof data.total_to_import !== 'undefined' && data.total_to_import === 0) {
                           updateProgress(1,1, 'Concluído! (Nenhum item para importar)');
                        } else {
                            updateProgress(1,1, 'Concluído!');
                        }
                        cleanup(true, data);
                    } else if (data.status === 'cancelled') {
                        appendLog(`Importação de ${getTypeName(type)} cancelada.`);
                        cleanup(false, data); 
                    } else if (data.status === 'error') {
                        appendLog(`Erro durante a importação de ${getTypeName(type)}.`);
                        cleanup(false, data); 
                    } else { 
                        appendLog(`Importação de ${getTypeName(type)} finalizada com status: ${data.status || 'desconhecido'}.`);
                        cleanup(true, data); 
                    }
                } else { 
                    appendLog(`Erro na resposta do servidor para ${getTypeName(type)}: ` + (response.data?.message || response.data || 'Resposta sem sucesso do servidor.'));
                    cleanup(false, response.data); 
                }
            })
            .catch(error => {
                if (!isImporting || currentImportType !== type) { 
                    appendLog(`Importação de ${getTypeName(type)} interrompida (pós-catch).`);
                    return; 
                }
                appendLog(`Erro na requisição AJAX para ${getTypeName(type)}: ` + error.message);
                cleanup(false); 
            });
        };

        // NOVO FLUXO PARA PRODUTOS: preparação incremental da fila
        if (type === 'products') {
            let currentSubIndex = 0;
            const prepareQueueBatch = () => {
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'art_image_prepare_product_import_queue_batch', current_sub_index: currentSubIndex, _ajax_nonce: art_image_ajax.nonce })
                })
                .then(res => res.json())
                .then(response => {
                    if (!isImporting || currentImportType !== type) {
                        appendLog(`Preparação da fila de produtos interrompida.`);
                        return;
                    }
                    if (response.success) {
                        const data = response.data;
                        if (Array.isArray(data.logs)) {
                            data.logs.forEach(msg => appendLog(msg));
                        }
                        if (data.status === 'preparing' && data.has_more) {
                            // Atualiza progresso
                            updateProgress(data.current_sub_index, data.total_subs, `Preparando fila: ${data.current_sub_index}/${data.total_subs} subcategorias`);
                            currentSubIndex = data.current_sub_index;
                            setTimeout(prepareQueueBatch, 300);
                        } else if (data.status === 'completed') {
                            appendLog('Fila de produtos preparada. Iniciando importação em lotes...');
                            updateProgress(0, data.product_queue_count || 0, 'Preparando importação...');
                            processBatch(1); // Agora processBatch já está definido!
                        } else if (data.status === 'cancelled') {
                            appendLog('Preparação da fila de produtos cancelada.');
                            cleanup(false, data);
                        } else {
                            appendLog('Status inesperado na preparação da fila de produtos: ' + (data.status || 'desconhecido'));
                            cleanup(false, data);
                        }
                    } else {
                        appendLog('Erro na preparação da fila de produtos: ' + (response.data?.message || response.data || 'Erro desconhecido'));
                        cleanup(false, response.data);
                    }
                })
                .catch(error => {
                    appendLog('Erro AJAX na preparação da fila de produtos: ' + error.message);
                    cleanup(false);
                });
            };
            // Inicia preparação incremental
            prepareQueueBatch();
            // processBatch só será chamado após a preparação
            return;
        }

        // FLUXO PADRÃO PARA OUTROS TIPOS
        processBatch(currentPage);
    };

    buttons.forEach(button => {
        button.addEventListener('click', function () {
            const type = this.getAttribute('data-type');
            handleGenericBatchImport(this, type);
        });
    });

    if(clearLogBtn) {
        clearLogBtn.addEventListener('click', function() {
            logArea.textContent = '';
            appendLog('Log limpo.');
        });
    }

    if(mainCancelBtn) {
        mainCancelBtn.addEventListener('click', function() {
            if (isImporting && currentImportType) {
                let cancelAction = '';
                switch (currentImportType) {
                    case 'categories': cancelAction = 'art_image_cancel_categories_import'; break;
                    case 'subcategories': cancelAction = 'art_image_cancel_subcategories_import'; break;
                    case 'artists': cancelAction = 'art_image_cancel_artists_import'; break;
                    case 'products': cancelAction = 'art_image_cancel_products_import'; break;
                    default:
                        appendLog('Não é possível cancelar tipo de importação desconhecido: ' + currentImportType);
                        return;
                }

                appendLog(`Enviando solicitação de cancelamento para ${getTypeName(currentImportType)}...`);
                this.disabled = true;
                
                // Cancelar imediatamente no frontend
                isImporting = false;
                
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: cancelAction, _ajax_nonce: art_image_ajax.nonce })
                })
                .then(res => res.json())
                .then(response => {
                    if(response.success) {
                        appendLog(`Cancelamento de ${getTypeName(currentImportType)} processado com sucesso.`);
                        // Forçar reset do estado
                        resetState();
                    } else {
                        appendLog(`Erro ao processar cancelamento para ${getTypeName(currentImportType)}: ${response.data?.message || 'Erro desconhecido'}`);
                        // Mesmo com erro no servidor, manter o cancelamento local
                        resetState();
                    }
                })
                .catch(error => {
                     appendLog(`Erro na requisição de cancelamento para ${getTypeName(currentImportType)}: ${error.message}`);
                     // Mesmo com erro na requisição, manter o cancelamento local
                     resetState();
                });
            }
        });
    }

    appendLog('Sistema de importação Art Image pronto.');
    appendLog('Selecione uma opção acima para começar.');
    
    // Verifica se há importação em andamento e reconecta automaticamente
    checkAndReconnectImport();
    
    if (activeImportsSectionElement && activeImportsListElement && typeof art_image_ajax !== 'undefined') {
        fetchActiveImports(); 
        activeImportPollInterval = setInterval(fetchActiveImports, 7000); 
    } else {
        if (!activeImportsSectionElement || !activeImportsListElement) {
            console.warn("Elementos para 'Processos de Importação em Andamento' não encontrados no DOM.");
        }
        if(typeof art_image_ajax === 'undefined'){
            console.warn("Objeto art_image_ajax (nonce) não está disponível. A lista de processos ativos não funcionará.");
        }
    }
});