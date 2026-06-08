# 📋 Plano de Alteração: Sincronização Diária → Semanal

## 🎯 **Objetivo**
Transformar o sistema de sincronização atual de **diário** para **semanal**, mantendo a flexibilidade, robustez e facilidade de configuração.

---

## 🔍 **Análise da Arquitetura Atual**

### **Pontos de Configuração Identificados:**
1. **`admin/settings.php`** - Interface administrativa (linha 42-50)
2. **`includes/cron.php`** - Sistema de agendamento legacy
3. **`includes/sync-manager.php`** - Gerenciador principal (linha 207-218)
4. **`includes/timezone-helper.php`** - Utilitários de tempo

### **Eventos WP-Cron Atuais:**
- `art_image_daily_event` (legacy)
- `art_image_daily_sync` (principal)
- `art_image_run_sync_step` (passos individuais)

---

## 🛠️ **Plano de Implementação**

### **FASE 0: Preparação e Correção de Riscos (CRÍTICA)**

#### **0.1 Corrigir Definição de Constantes**
```php
// No arquivo principal ponto-design-art-image-importer.php
define('ART_IMAGE_PLUGIN_FILE', __FILE__);
```

#### **0.2 Limpeza Prioritária de Eventos Legacy**
```php
// Adicionar ao início do sync-manager.php
public static function cleanup_all_legacy_events() {
    // Remove eventos do sistema antigo (cron.php)
    wp_clear_scheduled_hook('art_image_daily_event');
    remove_all_actions('art_image_daily_event');
    
    // Remove hooks de ativação/desativação conflitantes
    remove_action('plugins_loaded', 'art_image_init_sync_manager');
    
    ArtImageTimezoneHelper::log_with_timezone('Eventos legacy limpos com sucesso');
}
```

#### **0.3 Verificação de Versão para Migração**
```php
// Sistema de versionamento seguro
public static function check_and_migrate() {
    $current_version = get_option('art_image_plugin_version', '0.0.0');
    $plugin_version = ART_IMAGE_VERSION;
    
    if (version_compare($current_version, $plugin_version, '<')) {
        self::cleanup_all_legacy_events();
        self::migrate_to_weekly_system();
        update_option('art_image_plugin_version', $plugin_version);
    }
}
```

### **FASE 1: Extensão do Sistema de Intervalos**

#### **1.1 Modificar cron.php**
```php
// Adicionar novo intervalo semanal
add_filter('cron_schedules', 'art_image_add_weekly_schedule');
function art_image_add_weekly_schedule($schedules) {
    $schedules['weekly'] = array(
        'interval' => 604800, // 7 dias em segundos
        'display'  => __('Weekly')
    );
    return $schedules;
}

// IMPORTANTE: Remover hooks de ativação conflitantes
// Comentar ou remover estas linhas:
// register_activation_hook(ART_IMAGE_PLUGIN_FILE, 'art_image_schedule_daily_event');
// register_deactivation_hook(ART_IMAGE_PLUGIN_FILE, 'art_image_unschedule_daily_event');
```

#### **1.2 Criar Função de Agendamento Flexível**
```php
function art_image_schedule_sync_event() {
    $schedule_time = get_option('art_image_schedule_time', '02:00');
    $schedule_frequency = get_option('art_image_schedule_frequency', 'weekly');
    $schedule_day = get_option('art_image_schedule_day', 'sunday'); // Para semanal
    
    if (!wp_next_scheduled('art_image_sync_event')) {
        if ($schedule_frequency === 'weekly') {
            $timestamp = ArtImageTimezoneHelper::get_next_weekly_execution_time($schedule_time, $schedule_day)->getTimestamp();
        } else {
            $timestamp = ArtImageTimezoneHelper::get_next_execution_time($schedule_time)->getTimestamp();
        }
        
        wp_schedule_event($timestamp, $schedule_frequency, 'art_image_sync_event');
    }
}
```

### **FASE 2: Atualização da Interface Administrativa**

#### **2.1 Modificar settings.php**
```php
// Substituir campo de horário por configuração completa
add_settings_field(
    'art_image_schedule_frequency',
    __('Frequência de Sincronização', 'art-image'),
    function () {
        $value = get_option('art_image_schedule_frequency', 'weekly');
        echo "<select name='art_image_schedule_frequency' class='regular-text'>";
        echo "<option value='daily'" . selected($value, 'daily', false) . ">Diária</option>";
        echo "<option value='weekly'" . selected($value, 'weekly', false) . ">Semanal</option>";
        echo "</select>";
    },
    'art_image_settings',
    'art_image_main_section'
);

add_settings_field(
    'art_image_schedule_day',
    __('Dia da Semana (para sincronização semanal)', 'art-image'),
    function () {
        $value = get_option('art_image_schedule_day', 'sunday');
        $days = [
            'sunday' => 'Domingo',
            'monday' => 'Segunda-feira',
            'tuesday' => 'Terça-feira',
            'wednesday' => 'Quarta-feira',
            'thursday' => 'Quinta-feira',
            'friday' => 'Sexta-feira',
            'saturday' => 'Sábado'
        ];
        echo "<select name='art_image_schedule_day' class='regular-text'>";
        foreach ($days as $key => $label) {
            echo "<option value='{$key}'" . selected($value, $key, false) . ">{$label}</option>";
        }
        echo "</select>";
        echo "<p class='description'>Aplicável apenas quando a frequência for 'Semanal'</p>";
    },
    'art_image_settings',
    'art_image_main_section'
);
```

#### **2.2 Registrar Novas Configurações com Validação**
```php
// Adicionar ao início da função admin_init
register_setting('art_image_settings_group', 'art_image_schedule_frequency', [
    'sanitize_callback' => 'art_image_sanitize_frequency',
    'default' => 'weekly'
]);

register_setting('art_image_settings_group', 'art_image_schedule_day', [
    'sanitize_callback' => 'art_image_sanitize_day',
    'default' => 'sunday'
]);

// Funções de validação
function art_image_sanitize_frequency($value) {
    $allowed = ['daily', 'weekly'];
    return in_array($value, $allowed) ? $value : 'weekly';
}

function art_image_sanitize_day($value) {
    $allowed = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    return in_array($value, $allowed) ? $value : 'sunday';
}
```

### **FASE 3: Extensão do TimezoneHelper**

#### **3.1 Adicionar Método para Execução Semanal**
```php
/**
 * Obtém o próximo horário de execução semanal
 */
public static function get_next_weekly_execution_time($hour = '02:00', $day = 'sunday') {
    $timezone = wp_timezone();
    $now = new DateTime('now', $timezone);
    
    // Mapear dias da semana
    $day_map = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6
    ];
    
    $target_day = $day_map[$day] ?? 0;
    $current_day = (int) $now->format('w');
    
    // Calcular próximo dia da semana
    $days_until_target = ($target_day - $current_day + 7) % 7;
    if ($days_until_target === 0) {
        // É hoje, verificar se já passou do horário
        $target_time = new DateTime('today ' . $hour, $timezone);
        if ($now >= $target_time) {
            $days_until_target = 7; // Próxima semana
        }
    }
    
    $target_date = clone $now;
    $target_date->add(new DateInterval('P' . $days_until_target . 'D'));
    $target_date->setTime(...explode(':', $hour));
    
    return $target_date;
}
```

#### **3.2 Atualizar get_scheduled_events_info()**
```php
// Adicionar verificação para evento semanal
$next_weekly = wp_next_scheduled('art_image_sync_event');
if ($next_weekly) {
    $events['weekly_sync'] = [
        'name' => 'Sincronização semanal',
        'timestamp' => $next_weekly,
        'date' => wp_date('Y-m-d H:i:s', $next_weekly),
        'hook' => 'art_image_sync_event'
    ];
}
```

### **FASE 4: Atualização do Sync Manager**

#### **4.1 Modificar schedule_daily_sync() com Fallback Robusto**
```php
/**
 * Agenda o evento principal baseado na configuração com verificações de segurança
 */
public static function schedule_sync_event() {
    // Verifica se WP-Cron está funcionando
    if (!self::is_wp_cron_working()) {
        ArtImageTimezoneHelper::log_with_timezone('AVISO: WP-Cron pode não estar funcionando corretamente');
        return false;
    }
    
    $frequency = get_option('art_image_schedule_frequency', 'weekly');
    $hook_name = 'art_image_sync_event';
    
    // Limpa agendamentos existentes para evitar duplicatas
    wp_clear_scheduled_hook($hook_name);
    
    $schedule_time = get_option('art_image_schedule_time', '02:00');
    
    // Validação do horário
    if (!ArtImageTimezoneHelper::validate_time_format($schedule_time)) {
        $schedule_time = '02:00';
        ArtImageTimezoneHelper::log_with_timezone('Horário inválido detectado, usando padrão: 02:00');
    }
    
    try {
        if ($frequency === 'weekly') {
            $schedule_day = get_option('art_image_schedule_day', 'sunday');
            $timestamp = ArtImageTimezoneHelper::get_next_weekly_execution_time($schedule_time, $schedule_day)->getTimestamp();
        } else {
            $timestamp = ArtImageTimezoneHelper::get_next_execution_time($schedule_time)->getTimestamp();
        }
        
        $result = wp_schedule_event($timestamp, $frequency, $hook_name);
        
        if ($result === false) {
            ArtImageTimezoneHelper::log_with_timezone('ERRO: Falha ao agendar sincronização');
            return false;
        }
        
        ArtImageTimezoneHelper::log_with_timezone("Sincronização {$frequency} agendada para: " . date('Y-m-d H:i:s', $timestamp));
        return true;
        
    } catch (Exception $e) {
        ArtImageTimezoneHelper::log_with_timezone('ERRO ao agendar: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verifica se o WP-Cron está funcionando
 */
private static function is_wp_cron_working() {
    $test_hook = 'art_image_cron_test_' . time();
    $test_time = time() + 60;
    
    $result = wp_schedule_single_event($test_time, $test_hook);
    if ($result === false) {
        return false;
    }
    
    $scheduled = wp_next_scheduled($test_hook);
    wp_unschedule_event($scheduled, $test_hook);
    
    return $scheduled !== false;
}
```

#### **4.2 Atualizar Hook de Inicialização**
```php
// Substituir no final do arquivo
add_action('init', ['ArtImageSyncManager', 'schedule_sync_event']);

// Adicionar hook para o novo evento
add_action('art_image_sync_event', function() {
    $manager = new ArtImageSyncManager();
    $manager->run_sync();
});
```

### **FASE 5: Migração e Limpeza**

#### **5.1 Script de Migração Seguro**
```php
/**
 * Migra configurações existentes para o novo sistema
 */
public static function migrate_to_weekly_system() {
    ArtImageTimezoneHelper::log_with_timezone('=== INICIANDO MIGRAÇÃO PARA SISTEMA SEMANAL ===');
    
    // Backup das configurações atuais
    $backup_data = [
        'old_schedule_time' => get_option('art_image_schedule_time', '02:00'),
        'migration_date' => current_time('Y-m-d H:i:s'),
        'old_events' => []
    ];
    
    // Registra eventos existentes antes da limpeza
    $old_daily_sync = wp_next_scheduled('art_image_daily_sync');
    $old_daily_event = wp_next_scheduled('art_image_daily_event');
    
    if ($old_daily_sync) {
        $backup_data['old_events']['daily_sync'] = date('Y-m-d H:i:s', $old_daily_sync);
    }
    if ($old_daily_event) {
        $backup_data['old_events']['daily_event'] = date('Y-m-d H:i:s', $old_daily_event);
    }
    
    update_option('art_image_migration_backup', $backup_data);
    
    // Define padrões se não existirem
    if (!get_option('art_image_schedule_frequency')) {
        update_option('art_image_schedule_frequency', 'weekly');
        ArtImageTimezoneHelper::log_with_timezone('Frequência definida como semanal (padrão)');
    }
    
    if (!get_option('art_image_schedule_day')) {
        update_option('art_image_schedule_day', 'sunday');
        ArtImageTimezoneHelper::log_with_timezone('Dia definido como domingo (padrão)');
    }
    
    // Limpeza completa de eventos antigos
    self::cleanup_all_legacy_events();
    
    // Agenda novo evento com verificação
    $success = self::schedule_sync_event();
    
    if ($success) {
        ArtImageTimezoneHelper::log_with_timezone('=== MIGRAÇÃO CONCLUÍDA COM SUCESSO ===');
        update_option('art_image_migration_status', 'completed');
        return true;
    } else {
        ArtImageTimezoneHelper::log_with_timezone('=== ERRO NA MIGRAÇÃO ===');
        update_option('art_image_migration_status', 'failed');
        return false;
    }
}
```

#### **5.2 Hook de Migração Seguro**
```php
// Adicionar ao final do sync-manager.php
add_action('admin_init', ['ArtImageSyncManager', 'check_and_migrate']);

// Hook de ativação apenas para limpeza inicial
register_activation_hook(ART_IMAGE_PLUGIN_FILE, function() {
    // Apenas força uma verificação na próxima carga admin
    delete_option('art_image_plugin_version');
});
```

#### **5.3 Sistema de Rollback**
```php
/**
 * Reverte o sistema para sincronização diária
 */
public static function rollback_to_daily_system() {
    ArtImageTimezoneHelper::log_with_timezone('=== INICIANDO ROLLBACK PARA SISTEMA DIÁRIO ===');
    
    // Recupera backup da migração
    $backup_data = get_option('art_image_migration_backup', []);
    
    if (empty($backup_data)) {
        ArtImageTimezoneHelper::log_with_timezone('ERRO: Backup não encontrado para rollback');
        return false;
    }
    
    // Limpa eventos semanais
    wp_clear_scheduled_hook('art_image_sync_event');
    
    // Remove configurações semanais
    delete_option('art_image_schedule_frequency');
    delete_option('art_image_schedule_day');
    
    // Restaura configuração de horário se existir no backup
    if (isset($backup_data['old_schedule_time'])) {
        update_option('art_image_schedule_time', $backup_data['old_schedule_time']);
    }
    
    // Reagenda evento diário usando a função original
    $schedule_time = get_option('art_image_schedule_time', '02:00');
    $timezone = ArtImageTimezoneHelper::get_timezone_info();
    $next_run = ArtImageTimezoneHelper::get_next_execution_time($schedule_time);
    
    if ($next_run) {
        wp_schedule_event($next_run, 'daily', 'art_image_daily_sync');
        ArtImageTimezoneHelper::log_with_timezone('Evento diário reagendado para: ' . date('Y-m-d H:i:s', $next_run));
    }
    
    // Marca rollback como concluído
    update_option('art_image_migration_status', 'rolled_back');
    update_option('art_image_rollback_date', current_time('Y-m-d H:i:s'));
    
    ArtImageTimezoneHelper::log_with_timezone('=== ROLLBACK CONCLUÍDO COM SUCESSO ===');
    return true;
}

/**
 * Função administrativa para executar rollback
 */
public static function admin_rollback() {
    if (!current_user_can('manage_options')) {
        wp_die('Acesso negado');
    }
    
    if (isset($_POST['confirm_rollback']) && $_POST['confirm_rollback'] === 'yes') {
        $success = self::rollback_to_daily_system();
        
        if ($success) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Sistema revertido para sincronização diária com sucesso!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Erro ao reverter sistema. Verifique os logs.</p></div>';
            });
        }
    }
}
```

---

## 🔄 **Cronograma de Implementação**

### **Sprint 1 (2-3 dias)**
- ✅ Extensão do sistema de intervalos (cron.php)
- ✅ Atualização da interface administrativa (settings.php)
- ✅ Registro das novas configurações

### **Sprint 2 (2-3 dias)**
- ✅ Extensão do TimezoneHelper
- ✅ Atualização do SyncManager
- ✅ Testes de agendamento

### **Sprint 3 (1-2 dias)**
- ✅ Script de migração
- ✅ Limpeza de eventos legacy
- ✅ Testes de integração
- ✅ Documentação

---

## 🧪 **Estratégia de Testes**

### **1. Testes Unitários:**
- Validação de cálculo de próxima execução semanal
- Verificação de agendamento correto
- Teste de diferentes dias da semana

### **2. Testes de Integração:**
- Migração de configurações existentes
- Limpeza de eventos antigos
- Interface administrativa
- Salvamento de configurações

### **3. Testes de Regressão:**
- Manter compatibilidade com sincronização diária
- Verificar logs e monitoramento
- Validar timezone handling

### **4. Cenários de Teste:**
```
# Cenário 1: Migração de sistema existente
- Sistema atual: diário às 02:00
- Resultado esperado: semanal domingo às 02:00

# Cenário 2: Configuração manual
- Usuário escolhe: semanal quarta-feira às 14:30
- Resultado esperado: próxima quarta às 14:30

# Cenário 3: Mudança de configuração
- Alterar de domingo para terça-feira
- Resultado esperado: reagendamento automático
```

---

## 🔒 **Considerações de Segurança**

- **Backward Compatibility:** Manter opção diária disponível
- **Validação de Entrada:** Sanitizar novos campos de configuração
- **Fallback:** Sistema deve funcionar mesmo com configurações inválidas
- **Logs:** Registrar todas as mudanças de configuração
- **Permissions:** Verificar capacidades do usuário antes de salvar

---

## 📈 **Benefícios Esperados**

- ✅ **Redução de Carga:** 85% menos execuções de sincronização
- ✅ **Flexibilidade:** Usuário escolhe dia e horário da semana
- ✅ **Manutenibilidade:** Código mais organizado e extensível
- ✅ **Monitoramento:** Melhor controle sobre quando ocorrem as sincronizações
- ✅ **Performance:** Menor impacto no servidor
- ✅ **Recursos:** Economia de CPU e memória

---

## 📝 **Checklist de Implementação**

### **Fase 0: Preparação e Correção de Riscos** ✅
- [ ] Definir `ART_IMAGE_PLUGIN_FILE` no arquivo principal
- [ ] Implementar `cleanup_all_legacy_events()` em sync-manager.php
- [ ] Implementar `check_and_migrate()` com verificação de versão
- [ ] Testar limpeza de eventos legados

### **Fase 1: Extensão do Sistema de Timezone** ✅
- [ ] Adicionar métodos semanais ao `ArtImageTimezoneHelper`
- [ ] Implementar `get_next_weekly_execution_time()`
- [ ] Implementar `get_weekly_schedule_info()`
- [ ] Testar cálculos de próxima execução semanal

### **Fase 2: Atualização do Sync Manager** ✅
- [ ] Modificar `schedule_daily_sync()` para `schedule_sync_event()`
- [ ] Implementar lógica condicional (diária/semanal)
- [ ] Adicionar validação robusta e fallback
- [ ] Atualizar `get_scheduled_events_info()`
- [ ] Testar agendamento em ambos os modos

### **Fase 3: Interface Administrativa** ✅
- [ ] Adicionar campos de frequência e dia em settings.php
- [ ] Implementar funções de sanitização
- [ ] Atualizar interface de diagnósticos
- [ ] Testar salvamento de configurações

### **Fase 4: Atualização do Cron** ✅
- [ ] Remover hooks de ativação conflitantes
- [ ] Manter apenas funções de utilidade
- [ ] Testar compatibilidade

### **Fase 5: Migração e Testes** ✅
- [ ] Implementar migração segura com backup
- [ ] Implementar sistema de rollback
- [ ] Executar testes extensivos
- [ ] Validar em ambiente de produção

---

## 🧪 **Testes e Validação**

### **Testes Unitários**
```php
// Teste de agendamento semanal
public function test_weekly_scheduling() {
    // Configura sistema semanal
    update_option('art_image_schedule_frequency', 'weekly');
    update_option('art_image_schedule_day', 'sunday');
    update_option('art_image_schedule_time', '02:00');
    
    // Testa agendamento
    $result = ArtImageSyncManager::schedule_sync_event();
    $this->assertTrue($result);
    
    // Verifica se evento foi agendado
    $next_run = wp_next_scheduled('art_image_sync_event');
    $this->assertNotFalse($next_run);
    
    // Verifica se é domingo às 02:00
    $day_of_week = date('w', $next_run); // 0 = domingo
    $hour = date('H', $next_run);
    $this->assertEquals(0, $day_of_week);
    $this->assertEquals('02', $hour);
}

// Teste de migração
public function test_migration() {
    // Simula sistema diário existente
    wp_schedule_event(time() + 3600, 'daily', 'art_image_daily_sync');
    
    // Executa migração
    $result = ArtImageSyncManager::migrate_to_weekly_system();
    $this->assertTrue($result);
    
    // Verifica se eventos antigos foram removidos
    $old_event = wp_next_scheduled('art_image_daily_sync');
    $this->assertFalse($old_event);
    
    // Verifica se novo evento foi criado
    $new_event = wp_next_scheduled('art_image_sync_event');
    $this->assertNotFalse($new_event);
}

// Teste de rollback
public function test_rollback() {
    // Simula migração com backup
    update_option('art_image_migration_backup', [
        'old_schedule_time' => '03:00',
        'migration_date' => current_time('Y-m-d H:i:s')
    ]);
    
    // Executa rollback
    $result = ArtImageSyncManager::rollback_to_daily_system();
    $this->assertTrue($result);
    
    // Verifica se configurações foram restauradas
    $schedule_time = get_option('art_image_schedule_time');
    $this->assertEquals('03:00', $schedule_time);
    
    // Verifica se evento diário foi reagendado
    $daily_event = wp_next_scheduled('art_image_daily_sync');
    $this->assertNotFalse($daily_event);
}
```

### **Testes Manuais**
1. **Teste de Interface:**
   - Acessar configurações do plugin
   - Alterar frequência para semanal
   - Selecionar dia da semana
   - Salvar configurações
   - Verificar se valores foram salvos corretamente

2. **Teste de Agendamento:**
   - Configurar sincronização semanal
   - Verificar na página de diagnósticos
   - Confirmar próxima execução
   - Testar execução forçada

3. **Teste de Migração:**
   - Backup do site
   - Executar migração
   - Verificar logs
   - Testar rollback se necessário

4. **Teste de Produção:**
   - Monitorar primeira execução semanal
   - Verificar importação de dados
   - Confirmar funcionamento normal

### **Arquivos a Modificar:**
- [ ] `includes/cron.php` - Adicionar intervalo semanal
- [ ] `admin/settings.php` - Nova interface de configuração
- [ ] `includes/timezone-helper.php` - Método de execução semanal
- [ ] `includes/sync-manager.php` - Agendamento flexível
- [ ] `ponto-design-art-image-importer.php` - Hook de migração

### **Novas Configurações WordPress:**
- [ ] `art_image_schedule_frequency` (daily/weekly)
- [ ] `art_image_schedule_day` (sunday-saturday)

### **Novos Hooks WP-Cron:**
- [ ] `art_image_sync_event` - Evento principal unificado

### **Limpeza:**
- [ ] Remover `art_image_daily_event`
- [ ] Remover `art_image_daily_sync`
- [ ] Atualizar documentação

---

## 🚀 **Próximos Passos**

1. **Aprovação do Plano** - Validar abordagem com stakeholders
2. **Setup do Ambiente** - Preparar ambiente de desenvolvimento
3. **Implementação Fase 1** - Começar com extensão do sistema de intervalos
4. **Testes Incrementais** - Validar cada fase antes de prosseguir
5. **Deploy Gradual** - Implementar em ambiente de staging primeiro
6. **Monitoramento** - Acompanhar logs após deploy em produção

---

## 📚 **Referências Técnicas**

- [WordPress Cron API](https://developer.wordpress.org/plugins/cron/)
- [WordPress Settings API](https://developer.wordpress.org/plugins/settings/)
- [PHP DateTime](https://www.php.net/manual/en/class.datetime.php)
- [WordPress Timezone Functions](https://developer.wordpress.org/reference/functions/wp_timezone/)

---

**Documento criado em:** " . date('Y-m-d H:i:s') . "  
**Versão:** 1.0  
**Autor:** Desenvolvimento UnitedWeb