<?php
/**
 * Plugin Name: WRJ WebP Auto Converter
 * Description: Converte uploads JPG/PNG para WebP com inteligência de contexto. Produtos: 2 MP, quadrados, leves e nítidos para público exigente. Protege contra bombas de descompressão e dá "NÃO" claro ao operador.
 * Version:     1.5.0
 * Author:      WRJ
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit; // Sem acesso direto
}

/* ----------------------------------------------------------------------------
 * Atualizações automáticas via GitHub (Plugin Update Checker)
 *
 * Checa os Releases publicados em github.com/acacioojunior-maker/...
 * e mostra "atualização disponível" no painel, como um plugin do diretório.
 * ------------------------------------------------------------------------- */

$wrj_webp_puc_loader = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
if (file_exists($wrj_webp_puc_loader)) {
    require $wrj_webp_puc_loader;

    $wrj_webp_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/acacioojunior-maker/wrj-webp-auto-converter/',
        __FILE__,
        'wrj-webp-auto-converter'
    );
}

/* ----------------------------------------------------------------------------
 * Configuração Base
 * ------------------------------------------------------------------------- */

// Limites de ENTRADA (os "NÃO" para o operador)
if (!defined('WRJ_WEBP_MAX_UPLOAD_MB')) {
    define('WRJ_WEBP_MAX_UPLOAD_MB', 1); // Peso máximo do arquivo enviado (MB)
}
if (!defined('WRJ_WEBP_MAX_MEGAPIXELS_BOMB')) {
    define('WRJ_WEBP_MAX_MEGAPIXELS_BOMB', 25); // Fusível anti-bomba (resolução máx em MP)
}
if (!defined('WRJ_WEBP_SQUARE_TOLERANCE')) {
    define('WRJ_WEBP_SQUARE_TOLERANCE', 0.02); // Tolerância p/ considerar "quadrada" (2%)
}

// Compressão
if (!defined('WRJ_WEBP_START_QUALITY')) {
    define('WRJ_WEBP_START_QUALITY', 90); // Qualidade inicial da tentativa
}

// Perfil PRODUTO (público exigente, mas leve no servidor)
if (!defined('WRJ_WEBP_PRODUCT_MAX_MP')) {
    define('WRJ_WEBP_PRODUCT_MAX_MP', 2.0); // ~1400x1400
}
if (!defined('WRJ_WEBP_PRODUCT_MAX_KB')) {
    define('WRJ_WEBP_PRODUCT_MAX_KB', 350);
}
if (!defined('WRJ_WEBP_PRODUCT_MIN_QUALITY')) {
    define('WRJ_WEBP_PRODUCT_MIN_QUALITY', 80);
}

// Perfil PADRÃO (posts, páginas, mídia geral)
if (!defined('WRJ_WEBP_DEFAULT_MAX_KB')) {
    define('WRJ_WEBP_DEFAULT_MAX_KB', 200);
}
if (!defined('WRJ_WEBP_DEFAULT_MIN_QUALITY')) {
    define('WRJ_WEBP_DEFAULT_MIN_QUALITY', 60);
}

// true  = apaga o original de vez
// false = move o original para wp-content/wrj-originais-backup/
if (!defined('WRJ_WEBP_DELETE_ORIGINAL')) {
    define('WRJ_WEBP_DELETE_ORIGINAL', true);
}

/* ----------------------------------------------------------------------------
 * Garante que o WordPress aceite o mimetype webp
 * ------------------------------------------------------------------------- */

add_filter('upload_mimes', function ($mimes) {
    $mimes['webp'] = 'image/webp';
    return $mimes;
});

/* ----------------------------------------------------------------------------
 * Aviso no painel se o servidor não suportar WebP (GD sem imagewebp)
 * ------------------------------------------------------------------------- */

add_action('admin_notices', 'wrj_webp_admin_notice_support');

function wrj_webp_admin_notice_support() {
    if (function_exists('imagewebp')) {
        return; // Servidor OK, nada a avisar
    }

    // Só mostra a quem pode mexer em plugins
    if (!current_user_can('activate_plugins')) {
        return;
    }

    echo '<div class="notice notice-warning"><p><strong>WRJ WebP Auto Converter:</strong> '
        . 'este servidor não tem suporte a WebP na extensão GD do PHP (função <code>imagewebp</code> ausente). '
        . 'A conversão está <strong>inativa</strong> — os uploads continuam funcionando no formato original, sem otimização. '
        . 'Peça à sua hospedagem para habilitar o suporte a WebP na GD.'
        . '</p></div>';
}

/* ----------------------------------------------------------------------------
 * Resolve o PERFIL de processamento conforme o contexto do upload
 * ------------------------------------------------------------------------- */

function wrj_webp_resolve_profile($filename) {
    $post_id   = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
    $post_type = $post_id > 0 ? get_post_type($post_id) : '';
    $name      = strtolower(basename((string) $filename));

    // Padrão (posts, páginas, mídia geral)
    $profile = [
        'context'        => 'default',
        'cap_mode'       => 'dim',   // 'dim' = limita pelo lado maior | 'mp' = limita por megapixels
        'cap_value'      => 1200,
        'max_kb'         => WRJ_WEBP_DEFAULT_MAX_KB,
        'min_quality'    => WRJ_WEBP_DEFAULT_MIN_QUALITY,
        'min_width'      => 600,     // piso da redução progressiva
        'require_square' => false,
    ];

    // Cenário A: PRODUTO — 2 MP, quadrado, nítido e leve
    if ($post_type === 'product') {
        $profile = [
            'context'        => 'product',
            'cap_mode'       => 'mp',
            'cap_value'      => WRJ_WEBP_PRODUCT_MAX_MP,
            'max_kb'         => WRJ_WEBP_PRODUCT_MAX_KB,
            'min_quality'    => WRJ_WEBP_PRODUCT_MIN_QUALITY,
            'min_width'      => 1000,
            'require_square' => true,
        ];
    }
    // Cenário B: LOGOTIPOS e Identidade Visual
    elseif (preg_match('/(logo|marca|brand|icon|avatar|favicon|identidade)/', $name)) {
        $profile = [
            'context'        => 'logo',
            'cap_mode'       => 'dim',
            'cap_value'      => 800,
            'max_kb'         => 120,
            'min_quality'    => 82,
            'min_width'      => 400,
            'require_square' => false,
        ];
    }

    return $profile;
}

/* ----------------------------------------------------------------------------
 * VALIDAÇÃO DE ENTRADA (todos os "NÃO" juntos, antes de processar)
 *   1) Peso > 1 MB
 *   2) Resolução insana (bomba de descompressão)
 *   3) Produto não-quadrado
 * ------------------------------------------------------------------------- */

add_filter('wp_handle_upload_prefilter', 'wrj_webp_validate_upload');

function wrj_webp_validate_upload($file) {
    if (!empty($file['error'])) {
        return $file;
    }

    $type = $file['type'] ?? '';
    if (strpos($type, 'image/') !== 0) {
        return $file;
    }

    $nome = $file['name'] ?? 'arquivo';

    // 1) PESO MÁXIMO (o "NÃO" visível)
    $max_bytes = WRJ_WEBP_MAX_UPLOAD_MB * 1024 * 1024;
    if (!empty($file['size']) && $file['size'] > $max_bytes) {
        $file['error'] = sprintf(
            'A imagem "%s" tem %s e ultrapassa o limite de %d MB. Reduza o tamanho antes de enviar.',
            $nome,
            size_format($file['size'], 1),
            WRJ_WEBP_MAX_UPLOAD_MB
        );
        return $file;
    }

    // Lê só o cabeçalho para conferir dimensões (barato, não abre a imagem)
    $tmp  = $file['tmp_name'] ?? '';
    $dims = $tmp ? @getimagesize($tmp) : false;
    if ($dims === false) {
        return $file; // não deu pra ler; deixa o WordPress/conversor decidir
    }

    list($w, $h) = $dims;

    // 2) FUSÍVEL ANTI-BOMBA (arquivo leve, mas resolução gigante)
    if (($w * $h) > (WRJ_WEBP_MAX_MEGAPIXELS_BOMB * 1000000)) {
        wrj_webp_log("Bomba de descompressão bloqueada: {$nome} ({$w}x{$h})");
        $file['error'] = sprintf(
            'A imagem "%s" tem resolução muito alta (%s MP). Envie uma imagem de até %d MP.',
            $nome,
            number_format(($w * $h) / 1000000, 1, ',', '.'),
            WRJ_WEBP_MAX_MEGAPIXELS_BOMB
        );
        return $file;
    }

    // 3) PRODUTO PRECISA SER QUADRADO (1:1, com tolerância)
    $profile = wrj_webp_resolve_profile($nome);
    if (!empty($profile['require_square'])) {
        $desvio = abs($w - $h) / max($w, $h);
        if ($desvio > WRJ_WEBP_SQUARE_TOLERANCE) {
            $file['error'] = sprintf(
                'A foto de produto "%s" precisa ser QUADRADA (proporção 1:1). Esta tem %d×%d px. Ajuste largura e altura para o mesmo valor (ex.: 1400×1400) antes de enviar.',
                $nome,
                $w,
                $h
            );
            return $file;
        }
    }

    return $file;
}

/* ----------------------------------------------------------------------------
 * Conversão e Otimização Inteligente no Upload
 * ------------------------------------------------------------------------- */

add_filter('wp_handle_upload', 'wrj_webp_convert_upload');

function wrj_webp_convert_upload($file) {
    if (!function_exists('imagewebp')) {
        return $file;
    }

    $type = $file['type'] ?? '';
    if (!in_array($type, ['image/jpeg', 'image/png'], true)) {
        return $file;
    }

    $path    = $file['file'];
    $profile = wrj_webp_resolve_profile($path);

    $dims = @getimagesize($path);
    if ($dims === false || !in_array($dims[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return $file;
    }

    $img = @imagecreatefromstring(file_get_contents($path));
    if (!$img) {
        return $file;
    }

    $img = wrj_webp_fix_orientation($img, $path, $dims[2]);

    // Corte de resolução conforme o perfil
    if ($profile['cap_mode'] === 'mp') {
        $img = wrj_webp_resize_to_megapixels($img, $profile['cap_value']);
    } else {
        $img = wrj_webp_resize_to_max($img, $profile['cap_value']);
    }

    if (function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($img);
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);

    $info      = pathinfo($path);
    $webp_name = wp_unique_filename($info['dirname'], $info['filename'] . '.webp');
    $webp_path = trailingslashit($info['dirname']) . $webp_name;
    $max_bytes = $profile['max_kb'] * 1024;

    // Compressão por qualidade (output buffering, sem I/O de disco)
    $quality   = WRJ_WEBP_START_QUALITY;
    $ok        = false;
    $webp_data = '';
    $size      = PHP_INT_MAX;

    do {
        ob_start();
        $ok        = imagewebp($img, null, $quality);
        $webp_data = ob_get_clean();
        $size      = $ok ? strlen($webp_data) : PHP_INT_MAX;
        $quality  -= 5;
    } while ($ok && $size > $max_bytes && $quality >= $profile['min_quality']);

    // Redução de dimensão progressiva (respeitando o piso do contexto)
    while ($ok && $size > $max_bytes && imagesx($img) > $profile['min_width']) {
        $new_w = (int) (imagesx($img) * 0.85);
        $new_h = (int) (imagesy($img) * 0.85);

        $resized = imagecreatetruecolor($new_w, $new_h);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, imagesx($img), imagesy($img));

        imagedestroy($img);
        $img = $resized;

        ob_start();
        $ok        = imagewebp($img, null, $profile['min_quality']);
        $webp_data = ob_get_clean();
        $size      = $ok ? strlen($webp_data) : PHP_INT_MAX;
    }

    imagedestroy($img);

    if (!$ok || empty($webp_data)) {
        wrj_webp_log("Falha ao gerar WebP na memória, mantendo original: {$path}");
        return $file;
    }

    if (file_put_contents($webp_path, $webp_data) === false) {
        return $file;
    }

    wrj_webp_dispose_original($path);

    $file['file'] = $webp_path;
    $file['url']  = trailingslashit(dirname($file['url'])) . basename($webp_path);
    $file['type'] = 'image/webp';

    return $file;
}

/* ----------------------------------------------------------------------------
 * Funções Auxiliares
 * ------------------------------------------------------------------------- */

// Limita pela MAIOR medida (lado). Usado por logo/padrão.
function wrj_webp_resize_to_max($img, $max) {
    $w = imagesx($img);
    $h = imagesy($img);

    if ($w <= $max && $h <= $max) {
        return $img;
    }

    $ratio = min($max / $w, $max / $h);
    $new_w = max(1, (int) round($w * $ratio));
    $new_h = max(1, (int) round($h * $ratio));

    return wrj_webp_redraw($img, $new_w, $new_h);
}

// Limita por MEGAPIXELS (área total). Usado por produto — cumpre "2 MP" de verdade.
function wrj_webp_resize_to_megapixels($img, $max_mp) {
    $w          = imagesx($img);
    $h          = imagesy($img);
    $max_pixels = $max_mp * 1000000;
    $cur_pixels = $w * $h;

    if ($cur_pixels <= $max_pixels) {
        return $img;
    }

    $ratio = sqrt($max_pixels / $cur_pixels);
    $new_w = max(1, (int) round($w * $ratio));
    $new_h = max(1, (int) round($h * $ratio));

    return wrj_webp_redraw($img, $new_w, $new_h);
}

// Redesenha em nova dimensão preservando alfa e destruindo o recurso antigo.
function wrj_webp_redraw($img, $new_w, $new_h) {
    $resized = imagecreatetruecolor($new_w, $new_h);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $new_w, $new_h, imagesx($img), imagesy($img));

    imagedestroy($img);
    return $resized;
}

function wrj_webp_fix_orientation($img, $path, $image_type) {
    if ($image_type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
        return $img;
    }

    $exif = @exif_read_data($path);
    if (empty($exif['Orientation'])) {
        return $img;
    }

    $angle = 0;
    switch ((int) $exif['Orientation']) {
        case 3: $angle = 180; break;
        case 6: $angle = -90; break;
        case 8: $angle = 90;  break;
    }

    if ($angle !== 0) {
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated) {
            imagedestroy($img); // evita o vazamento de memória do recurso antigo
            $img = $rotated;
        }
    }

    return $img;
}

function wrj_webp_dispose_original($path) {
    if (WRJ_WEBP_DELETE_ORIGINAL) {
        if (!@unlink($path)) {
            wrj_webp_log("Não foi possível apagar o original: {$path}");
        }
        return;
    }

    $backup_dir = trailingslashit(WP_CONTENT_DIR) . 'wrj-originais-backup';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
        wrj_webp_protect_dir($backup_dir);
    }

    $sub      = wp_date('Y/m');
    $dest_dir = trailingslashit($backup_dir) . $sub;
    if (!file_exists($dest_dir)) {
        wp_mkdir_p($dest_dir);
    }

    $dest = trailingslashit($dest_dir) . basename($path);
    if (!@rename($path, $dest)) {
        if (!@unlink($path)) {
            wrj_webp_log("Não foi possível mover nem apagar o original: {$path}");
        }
    }
}

function wrj_webp_protect_dir($dir) {
    $htaccess = trailingslashit($dir) . '.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    $index = trailingslashit($dir) . 'index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php // Silence is golden.\n");
    }
}

function wrj_webp_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WRJ WebP] ' . $message);
    }
}
