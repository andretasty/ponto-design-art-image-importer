# WP Remote Import Sync

## Descrição

Plugin para WordPress que realiza a importação de categorias, produtos e artistas de um site externo. A importação pode ser feita manualmente através do painel administrativo, ou automaticamente via agendamento diário.

## Funcionalidades

- Login automático no site de terceiros.
- Importação de dados via botão manual ou cron job.
- Painel administrativo com:
  - Aba de configurações (email, senha, horário da tarefa).
  - Aba de importação manual com log em tempo real.
- Importação assíncrona para evitar travamentos da interface do usuário.
- Organização modular de código para facilitar manutenção e escalabilidade.

## Estrutura

- `/admin`: Interface administrativa, scripts e estilos.
- `/includes`: Núcleo da lógica de integração, importação e cron jobs.
- `/logs`: Armazenamento de logs de importação.
- `wp-remote-import-sync.php`: Arquivo principal de carregamento do plugin.

## Requisitos

- WordPress 5.8+
- PHP 7.4+

## Instalação

1. Faça upload da pasta `wp-remote-import-sync` para o diretório `/wp-content/plugins/`.
2. Ative o plugin no painel administrativo.
3. Acesse **Configurações > WP Remote Import Sync** para configurar.

## Estrutura de arquivos

wp-remote-import-sync/
│
├── wp-remote-import-sync.php         # Arquivo principal do plugin
├── readme.md                         # Documentação básica do plugin
├── uninstall.php                     # Limpeza de dados na desinstalação (se necessário)
│
├── /admin/
│   ├── admin-ui.php                  # Tela de administração com abas e campos
│   ├── assets/
│   │   ├── js/
│   │   │   └── import-logs.js        # Atualização em tempo real dos logs via AJAX
│   │   └── css/
│   │       └── admin-style.css       # Estilo do painel administrativo
│   └── settings.php                  # Registro e manipulação das opções de e-mail, senha e horário
│
├── /includes/
│   ├── loader.php                    # Inicialização do plugin, hooks, includes
│   ├── api-client.php                # Cliente HTTP para logar e buscar dados no site externo
│   ├── importer.php                  # Lógica de importação de categorias, produtos e artistas
│   ├── cron.php                      # Agendamento e execução das tarefas via cron
│   └── async-handler.php            # Processamento assíncrono dos dados (via WP AJAX ou WP Background Processing)
│
└── /logs/
    └── import-log-[type].log        # Logs separados por tipo: categoria, produto, artista

