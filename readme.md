# Art Image Importer

Plugin para importação e sincronização de produtos de arte.

## Instalação

1. Faça upload do plugin para a pasta `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Configure as credenciais de acesso na página de configurações do plugin

## Sincronização Automática

O plugin utiliza o WP Cron do WordPress para sincronização automática. A sincronização é agendada para ocorrer diariamente às 02:00.

Para garantir que o WP Cron funcione corretamente em sites com pouco tráfego, você pode:

1. Configurar um cron real do sistema para acionar o WP Cron:
   ```bash
   # Adicione ao crontab do sistema
   */15 * * * * wget -q -O - http://seu-site.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
   ```

2. Ou usar um serviço de monitoramento como o UptimeRobot para acessar o site periodicamente.

## Logs

O plugin mantém logs detalhados da sincronização em:
- `wp-content/art-image-sync.log`: Log detalhado da sincronização

## Funcionalidades

- Importação de categorias e subcategorias
- Importação de artistas
- Importação de produtos com imagens
- Sincronização automática via WP Cron
- Margem de lucro configurável (global e por categoria)
- Atualização automática de produtos existentes

## Suporte

Para suporte, entre em contato através do email: [seu-email@dominio.com]

