# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Art Image is a WordPress plugin that automatically imports categories, products, artists, and images from an external e-commerce site (artimage.com.br) into WooCommerce. The plugin handles authentication, scraping, batch processing, and scheduled synchronization with comprehensive error logging and timezone handling.

## Core Architecture

### Import Flow

The plugin follows a sequential batch import process:

1. **Categories** → Main product categories (taxonomy: `product_cat`)
2. **Subcategories** → Child categories with parent relationships
3. **Artists** → Custom taxonomy terms with images and metadata
4. **Products** → WooCommerce products with full details, images, and relationships

Each import type uses a queue-based batch processing system. For scheduled synchronization, the plugin uses **Action Scheduler** (from WooCommerce) for robust, persistent job queuing. Manual imports still use transients for real-time progress tracking.

### Key Components

**ArtImageImporter** (`includes/importer.php`)
- Core import logic for all entity types
- Batch processing with configurable sizes (default: 5 products, 20 for others)
- Master lock system prevents concurrent imports
- Queue management using WordPress transients
- Image downloading with duplicate detection via `_artimage_original_url` meta

**ArtImageSyncManager** (`includes/sync-manager.php`)
- Orchestrates scheduled full-site synchronization
- **Primary**: Uses Action Scheduler for robust scheduling (if WooCommerce available)
- **Fallback**: Uses WP-Cron for weekly/daily scheduling
- Step-based execution: categories → subcategories → artists → prepare_products → products
- Lock timeout: 12 hours
- Automatic cleanup and session tracking integration

**ArtImageASManager** (`includes/action-scheduler-manager.php`)
- Central class for Action Scheduler integration
- Schedules recurring sync (weekly/daily)
- Manages individual entity import actions
- Tracks phase completion and progress
- Provides retry mechanism for failed actions
- Cleanup of old completed actions

**ArtImageApiClient** (`includes/api-client.php`)
- Handles authentication with external site (3-step login process)
- Cookie management and validation
- HTML scraping for categories, products, and artist data
- Pagination support for product listings

**ArtImageSyncTracker** (`includes/sync-tracker.php`)
- Tracks imported entities to enable cleanup of orphaned items
- Stores SKUs, term IDs, and slugs for comparison
- Session-based sync with dry-run capability

**ArtImageTimezoneHelper** (`includes/timezone-helper.php`)
- Timezone-aware scheduling using WordPress timezone settings
- Weekly and daily execution time calculations
- Schedule validation and logging

### AJAX Handlers (`includes/async-handler.php`)

All manual imports use AJAX with nonce verification:
- `art_image_import_categories`
- `art_image_import_subcategories`
- `art_image_import_artists`
- `art_image_prepare_product_import_queue_batch`
- `art_image_import_products`

Cancellation handlers for each import type, plus maintenance endpoints:
- `art_image_clear_locks` - Clear all import locks
- `art_image_clear_queue` - Clear product queue
- `art_image_reset_all` - Complete reset
- `art_image_force_sync` - Trigger immediate sync
- `art_image_test_cron` - Test WP-Cron functionality

Action Scheduler endpoints:
- `art_image_as_get_progress` - Get current sync progress
- `art_image_as_start_sync` - Start sync via Action Scheduler
- `art_image_as_cancel` - Cancel all pending actions
- `art_image_as_retry_failed` - Retry failed actions
- `art_image_as_get_failed` - Get list of failed actions
- `art_image_as_cleanup` - Cleanup old completed actions

### Admin Interface (`admin/admin-ui.php`, `admin/settings.php`)

Tabs:
1. **Settings** - API credentials, sync schedule (frequency: daily/weekly, day of week, time)
2. **Discounts** - Custom discount rules by category
3. **Manual Import** - Batch import controls with real-time progress and logs
4. **Statistics** - Sync session stats (start/end times, counts)
5. **Debug/Fuso Horário** - Timezone info and scheduled events
6. **Diagnostics** - System health checks

## Important Patterns

### Master Lock System

Only one import type can run at a time, enforced by `artimage_master_import_lock` transient:
- Lock stores import type identifier ('categories', 'subcategories', 'products', 'artists')
- Each import type also has its own batch lock
- Locks expire after 15 minutes (categories/subcategories/artists) or use time-based control (products)

### Queue-Based Processing

Product imports use a two-phase approach:
1. **Preparation Phase**: `prepare_product_import_queue_batch()` iterates through subcategories, collecting products into `artimage_product_import_queue`
2. **Import Phase**: `import_products_batch()` processes queue in small batches with time limits

Transient keys follow pattern: `artimage_{type}_import_{queue|total|processed_count|batch_lock}`

### Image Handling

Images are downloaded via `artimage_download_and_attach_image()`:
- Checks for existing images using `_artimage_original_url` meta to prevent duplicates
- Downloads to WordPress media library
- Product images: first image = featured, rest = gallery (max 5 by default)
- Artist images: stored as term meta `thumbnail_id`

### Subcategory Slug Strategy

Subcategories use composite slugs to ensure uniqueness:
```php
$unique_slug = sanitize_title($sub_item['nome']) . '-' . $parent_cat->term_id;
```

Also stores `filtro_sub_id` term meta for API filtering.

## Development Workflow

### Testing Manual Imports

1. Navigate to Admin → Art Image → Manual Import
2. Click import button (runs batch-by-batch with progress bar)
3. Monitor real-time logs in the interface
4. Use Cancel button to stop mid-process

### Testing Scheduled Sync

```bash
# Force immediate execution
wp eval 'do_action("art_image_sync_event");'

# Check scheduled events
wp cron event list

# Test WP-Cron
wp eval 'file_put_contents("test.txt", "cron works");' && wp cron test
```

### Debug Logging

The plugin uses multiple logging mechanisms:
- `ArtImageTimezoneHelper::log_with_timezone()` - Main log with timestamps
- `art_image_log_import_error()` - Error logging with context
- `art_image_log_product_processing()` - Detailed product import tracking
- Log location: `wp-content/uploads/art-image-import-log.txt`

### Limit Imports for Testing

```php
// In functions.php or plugin code
add_filter('art_image_debug_subcategory_limit', function() {
    return 2; // Limit to 2 subcategories
});

add_filter('art_image_product_import_batch_size', function() {
    return 2; // Process 2 products per batch
});
```

## Common Tasks

### Clear Stuck Imports

Use the Diagnostics tab "Limpar Todos os Locks" button, or via code:
```php
delete_transient('artimage_master_import_lock');
delete_transient('artimage_product_import_queue');
// etc.
```

### Reschedule Sync Events

Admin → Settings → save schedule settings triggers `art_image_handle_reschedule_all()` which cleans legacy events and reschedules.

### View Sync Statistics

Admin → Statistics tab shows data from `ArtImageSyncTracker`:
- Last sync start/end times
- Items imported per session
- Total tracked entities

### Modify API Timeout

```php
add_filter('art_image_api_timeout', function() {
    return 60; // seconds
});
```

### Modify Image Download Timeout

```php
add_filter('art_image_download_timeout', function() {
    return 60; // seconds
});
```

## Configuration Options

Stored as WordPress options:
- `art_image_email` - API credentials
- `art_image_password` - API credentials
- `art_image_schedule_frequency` - 'daily' or 'weekly'
- `art_image_schedule_day` - Day of week for weekly sync (e.g., 'sunday')
- `art_image_schedule_time` - Time in 'HH:MM' format (e.g., '02:00')
- `artimage_cookies` - Cached authentication cookies
- Discount rules stored with keys like `art_image_discount_category_1_min`, `_max`, `_percent`

## Critical Notes

- **Never run multiple import types simultaneously** - Master lock prevents this
- **Product imports are resource-intensive** - Use appropriate batch sizes and monitor server load
- **Image URLs must be validated** - Use `art_image_is_valid_image_url()` helper
- **Subcategory slugs must be unique** - Current system appends parent term ID
- **WP-Cron dependency** - Scheduled sync requires functional WP-Cron (check via diagnostics)
- **Transients expire** - Queues use 12-hour expiration; interrupted imports may need manual cleanup

## File Structure

```
├── ponto-design-art-image-importer.php  # Main plugin file (constants, loader)
├── includes/
│   ├── loader.php                       # Loads all components
│   ├── logger.php                       # Centralized logging (ArtImageLogger)
│   ├── importer.php                     # Core import logic (1200+ lines)
│   ├── sync-manager.php                 # Scheduled sync orchestration
│   ├── action-scheduler-manager.php     # Action Scheduler integration
│   ├── async-handler.php                # AJAX handlers
│   ├── api-client.php                   # External API communication
│   ├── sync-tracker.php                 # Import tracking for cleanup
│   ├── timezone-helper.php              # Timezone utilities
│   ├── import-helpers.php               # Utilities + ArtImageLockManager class
│   ├── cron.php                         # WP-Cron fallback (legacy)
│   ├── pricing.php                      # Discount application logic
│   ├── product-fields.php               # Custom product meta (technique, frame, size)
│   ├── category-fields.php              # Custom category meta
│   └── post-types.php                   # Registers 'artist' taxonomy
├── admin/
│   ├── admin-ui.php                     # Admin interface tabs
│   ├── settings.php                     # Settings registration
│   └── diagnostics.php                  # System health checks
├── tests/                               # Test files (not loaded in production)
└── assets/                              # CSS/JS for admin interface
```

## Action Scheduler Integration

The plugin uses **Action Scheduler** (included with WooCommerce) for scheduled synchronization. This provides:

### Benefits over Transients/WP-Cron
- **Persistent storage**: Jobs stored in database tables, not volatile transients
- **Automatic retry**: Failed jobs are automatically retried
- **Visibility**: Built-in admin interface at Tools > Scheduled Actions
- **Scalability**: Parallel processing of multiple actions
- **Reliability**: Jobs survive server restarts, cache clears, etc.

### Action Scheduler Hooks
```
artimage_scheduled_sync          → Main recurring sync (weekly/daily)
artimage_start_import_phase      → Starts a phase (categories, products, etc.)
artimage_complete_phase          → Checks phase completion and advances
artimage_import_category         → Imports ONE category
artimage_import_subcategory      → Imports ONE subcategory
artimage_import_artist           → Imports ONE artist
artimage_import_product          → Imports ONE product
artimage_prepare_products_subcategory → Collects products from ONE subcategory
```

### Sync Flow with Action Scheduler
```
artimage_scheduled_sync
  └─> Creates session, schedules artimage_start_import_phase('categories')

artimage_start_import_phase('categories')
  └─> Fetches categories from API
  └─> Schedules artimage_import_category for EACH category
  └─> Schedules artimage_complete_phase('categories')

artimage_import_category (runs N times in parallel)
  └─> Imports single category

artimage_complete_phase('categories')
  └─> If still pending actions: reschedule check
  └─> If complete: schedule artimage_start_import_phase('subcategories')

... continues through subcategories, artists, prepare_products, products ...
```

### Checking Action Scheduler Status
```php
// Check if AS is available
ArtImageASManager::is_available();

// Get current progress
ArtImageASManager::get_progress();

// Check if sync is running
ArtImageASManager::is_sync_running();

// Cancel all pending actions
ArtImageASManager::cancel_all();

// Retry failed actions
ArtImageASManager::retry_failed();
```

### Diagnostics
The Diagnostics tab (admin/diagnostics.php) includes an Action Scheduler section showing:
- Whether AS is available and in use
- Current session and phase
- Statistics per phase (pending, running, complete, failed)
- Buttons to start sync, cancel, retry failed, and cleanup old actions
- Link to the full Action Scheduler admin page

## Migration & Legacy

The plugin migrated from transients/WP-Cron to Action Scheduler. This migration:
1. Clears old transients (product queue, locks, etc.)
2. Clears old WP-Cron events
3. Schedules sync via Action Scheduler

Legacy event hooks (`art_image_daily_event`, `art_image_daily_sync`) are cleaned up automatically via `ArtImageSyncManager::cleanup_all_legacy_events()`.

## WordPress Integration

- **Requires WooCommerce** - Products use WC_Product_Simple
- **Custom Taxonomy**: `artist` - Registered for products
- **Uses WordPress transients** extensively for state management
- **WP-Cron** for scheduling
- **WordPress media library** for image storage
- **WordPress Settings API** for configuration

## Utility Classes

### ArtImageLogger (`includes/logger.php`)
Sistema centralizado de logging que substitui os diversos métodos anteriores.

```php
// Log de nível INFO
ArtImageLogger::info('Mensagem de informação');

// Log de nível ERROR (também salva em transient para exibir no admin)
ArtImageLogger::error('Erro ocorrido', ['contexto' => 'valor']);

// Log de nível DEBUG (só funciona com WP_DEBUG ativo)
ArtImageLogger::debug('Mensagem de debug');

// Log específico para produtos
ArtImageLogger::product('SKU123', 'importado', ['imagens' => 5]);

// Log para Action Scheduler
ArtImageLogger::action_scheduler('Fase iniciada');

// Obter caminho do arquivo de log
ArtImageLogger::get_log_path();

// Limpar arquivo de log
ArtImageLogger::clear_log();
```

### ArtImageLockManager (`includes/import-helpers.php`)
Gerenciador centralizado de locks que unifica os diversos transients de lock anteriores.

```php
// Adquirir lock para importação
ArtImageLockManager::acquire('categories'); // ou 'products', 'artists', etc.

// Verificar se está travado
ArtImageLockManager::is_locked(); // qualquer tipo
ArtImageLockManager::is_locked('products'); // tipo específico

// Liberar lock
ArtImageLockManager::release('categories');

// Limpar todos os locks (força)
ArtImageLockManager::clear_all();

// Renovar timeout do lock atual
ArtImageLockManager::renew(1800); // 30 minutos

// Obter informações do lock atual
$lock = ArtImageLockManager::get_current();
// Retorna: ['type' => 'products', 'started' => timestamp, 'expires' => timestamp]
```

### Funções de Limpeza de Transients (`includes/import-helpers.php`)

```php
// Limpar todos os transients
art_image_clear_transients('all');

// Limpar apenas locks
art_image_clear_transients('locks');

// Limpar apenas filas
art_image_clear_transients('queues');

// Limpar transients de uma entidade específica
art_image_clear_transients(['products']);

// Limpar e liberar master lock
art_image_cleanup_entity_transients('categories');
```

## Configuration (wp-config.php)

As credenciais de API podem ser definidas no `wp-config.php` para maior segurança:

```php
// Adicione ao seu wp-config.php
define('ART_IMAGE_EMAIL', 'seu-email@exemplo.com');
define('ART_IMAGE_PASSWORD', 'sua-senha-aqui');

// Opcionalmente, também pode configurar agendamento:
define('ART_IMAGE_SCHEDULE_FREQUENCY', 'weekly'); // ou 'daily'
define('ART_IMAGE_SCHEDULE_DAY', 'saturday');
define('ART_IMAGE_SCHEDULE_TIME', '23:00');
```

Se não definidas no wp-config.php, as credenciais são lidas das options do WordPress (tela de configurações).
