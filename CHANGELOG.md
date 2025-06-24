# Changelog - Art Image Importer

## Versão 1.1.0 - Melhorias de Performance e Sincronização

### ✨ Novas Funcionalidades

1. **Sistema de Sincronização Completa**
   - Implementado tracking automático de itens importados
   - Remoção automática de produtos, categorias e artistas que não estão mais na fonte
   - Modo "Dry Run" para testar remoções sem executar
   - Nova aba "Estatísticas" na interface administrativa

2. **Reconexão Automática**
   - Sistema detecta importações em andamento ao recarregar a página
   - Reconecta automaticamente e continua o processo
   - Mantém progresso visual e logs

3. **Interface Aprimorada**
   - Nova aba de estatísticas com informações de sincronização
   - Botão para limpeza manual
   - Configurações para controlar comportamento de limpeza

### 🚀 Otimizações de Performance

1. **Importação Inteligente**
   - Produtos existentes: usa apenas dados da listagem (mais rápido)
   - Produtos novos: busca detalhes completos
   - Redução significativa no tempo de importação

2. **Gestão de Recursos**
   - Transients aumentados de 1h para 12h
   - Batch size otimizado (5 → 3 produtos por vez)
   - Limite de memória aumentado para 512MB
   - Timeout de PHP estendido para 5 minutos por batch

3. **Cache e Memória**
   - Suspensão temporária do cache durante importação
   - Melhor gerenciamento de memória

### 🔧 Correções

1. **Problema de Parada de Importação**
   - Corrigido problema que causava parada após ~260 produtos
   - Melhor persistência do estado de importação
   - Renovação automática de locks

2. **Estabilidade**
   - Tratamento de erros aprimorado
   - Recuperação automática de estado
   - Logs mais detalhados

### 🎛️ Configurações Novas

1. **Limpeza Automática**
   - `artimage_enable_cleanup`: Ativar/desativar limpeza automática
   - `artimage_cleanup_dry_run`: Modo de teste sem remover itens

2. **Tracking de Sincronização**
   - Metadados automáticos para rastreamento
   - Sessões de sincronização com IDs únicos
   - Estatísticas detalhadas

### 📊 Estatísticas e Monitoramento

1. **Nova Aba de Estatísticas**
   - Data/hora da última sincronização
   - Contadores de itens removidos
   - Status atual da sessão
   - Configurações ativas

2. **Logs Melhorados**
   - Registro de itens removidos
   - Diferenciação entre produtos novos e existentes
   - Timestamps de sincronização

### 🔄 Compatibilidade

- Mantém total compatibilidade com versão anterior
- Migrações automáticas de dados
- Não requer reconfiguração

### 📝 Arquivos Adicionados/Modificados

**Novos:**
- `includes/sync-tracker.php` - Sistema de tracking
- `includes/import-helpers.php` - Funções auxiliares

**Modificados:**
- `includes/importer.php` - Otimizações e tracking
- `admin/admin-ui.php` - Nova aba de estatísticas
- `admin/settings.php` - Novas configurações
- `admin/assets/js/import-logs.js` - Reconexão automática
- `includes/loader.php` - Carregamento dos novos módulos

### 🎯 Próximos Passos Sugeridos

1. Monitorar logs de sincronização
2. Ajustar configurações de limpeza conforme necessário
3. Verificar estatísticas regularmente
4. Considerar agendamento de cron real para sites com pouco tráfego 