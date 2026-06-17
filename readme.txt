=== WRJ WebP Auto Converter ===
Contributors: acacioojunior-maker
Tags: webp, otimização de imagem, performance, woocommerce, upload
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converte uploads JPG/PNG para WebP com regras por contexto e dá um "NÃO" claro ao operador quando a imagem foge do padrão do catálogo.

== Description ==

O **WRJ WebP Auto Converter** é uma cerca de proteção (guard-rail), não um editor de imagens. Ele garante que cada upload entre no site já otimizado em WebP, no tamanho e na proporção corretos para o seu contexto — sem que o operador precise entender de design.

Ele **nunca** altera a estética da foto: não remove fundo, não recorta, não mexe em cor, brilho ou enquadramento. Se a imagem foge do padrão, ele **recusa o upload com uma mensagem clara** e pede o ajuste na origem.

= Principais recursos =

* **Conversão automática para WebP** de uploads JPG e PNG.
* **Inteligência de contexto** — aplica um perfil diferente conforme onde a imagem é enviada:
    * **Produto** (`post_type = product`): máx. **2 MP** (~1400×1400), alvo **~350 KB**, qualidade 90→80, **obrigatoriamente quadrada (1:1)**.
    * **Logotipo/identidade** (nome contém `logo`, `marca`, `brand`, `icon`, `avatar`, `favicon`, `identidade`): máx. 800 px, alvo 120 KB.
    * **Padrão** (posts, páginas, mídia geral): máx. 1200 px, alvo 200 KB.
* **Limite de peso de upload** (padrão **1 MB**) com mensagem clara ao operador.
* **Fusível anti-bomba de descompressão** — recusa imagens de resolução absurda (padrão **25 MP**) lendo apenas o cabeçalho, antes de carregar o arquivo na memória.
* **Bloqueio de produto não-quadrado** com tolerância de 2%, para manter a apresentação padronizada do catálogo.
* **Compressão com economia de memória** via output buffering (sem I/O extra em disco).
* **Correção automática de orientação EXIF** (fotos deitadas/de lado).
* **Gestão do arquivo original** — apaga ou move para backup protegido (`.htaccess` + `index.php`).
* **Compatível com WooCommerce** no fluxo manual de upload (galeria e imagem destacada do produto).

= Para quem é =

Lojas de e-commerce (especialmente de alto ticket) onde fotos são enviadas por assistentes/gerentes de catálogo sem formação em design, e onde performance e padronização visual são críticas.

== Installation ==

1. Envie a pasta do plugin para `wp-content/plugins/` ou instale o `.zip` pelo painel.
2. Ative em **Plugins**.
3. Pronto. Os próximos uploads de imagem já são processados conforme o contexto.

Requer a extensão **GD** do PHP com suporte a WebP (`imagewebp`), disponível por padrão no PHP 7.4+. Se a função não existir, o plugin não converte e mantém o arquivo original intacto.

== Frequently Asked Questions ==

= O plugin mexe na aparência da foto? =
Não. Ele só converte o formato, ajusta resolução/peso e corrige rotação EXIF. Nunca recorta, remove fundo ou altera cores.

= Por que minha foto de produto foi recusada? =
Fotos de produto precisam ser **quadradas (1:1)**, com até 2% de tolerância. Ajuste a largura e a altura para o mesmo valor (ex.: 1400×1400) e reenvie.

= Por que existe um limite de 1 MB se o plugin já comprime? =
O limite de 1 MB é o "NÃO" visível para o operador, evitando arquivos pesados desnecessários. A conversão para WebP reduz ainda mais o peso final.

= O que é o fusível de 25 MP? Não é o mesmo que o 1 MB? =
Não. **MB** mede o peso do arquivo; **MP (megapixels)** mede a resolução. Um arquivo leve (ex.: 400 KB) pode ter resolução gigante (ex.: 16000×16000 = 256 MP) e estourar a memória do servidor ao ser aberto. O fusível de 25 MP bloqueia esse caso lendo apenas o cabeçalho, sem carregar a imagem.

= Funciona com importação em massa (CSV/API)? =
O plugin foi pensado para o fluxo **manual** de upload, onde o WordPress informa o `post_id` do produto. Em importações automatizadas esse contexto pode não estar disponível; nesses casos a imagem é tratada pelo perfil padrão.

= O original é perdido? =
Por padrão sim (`WRJ_WEBP_DELETE_ORIGINAL = true`). Defina como `false` para mover o original a uma pasta de backup protegida em `wp-content/wrj-originais-backup/`.

== Configuration ==

Todos os limites podem ser sobrescritos definindo as constantes no `wp-config.php` **antes** de `require_once ABSPATH . 'wp-settings.php';`:

`
// Entrada (os "NÃO" para o operador)
define( 'WRJ_WEBP_MAX_UPLOAD_MB',        1 );    // Peso máximo do upload (MB)
define( 'WRJ_WEBP_MAX_MEGAPIXELS_BOMB',  25 );   // Fusível anti-bomba (MP)
define( 'WRJ_WEBP_SQUARE_TOLERANCE',     0.02 ); // Tolerância para "quadrada" (2%)

// Compressão
define( 'WRJ_WEBP_START_QUALITY',        90 );   // Qualidade inicial

// Perfil Produto
define( 'WRJ_WEBP_PRODUCT_MAX_MP',       2.0 );  // ~1400x1400
define( 'WRJ_WEBP_PRODUCT_MAX_KB',       350 );
define( 'WRJ_WEBP_PRODUCT_MIN_QUALITY',  80 );

// Perfil Padrão
define( 'WRJ_WEBP_DEFAULT_MAX_KB',       200 );
define( 'WRJ_WEBP_DEFAULT_MIN_QUALITY',  60 );

// Gestão do original
define( 'WRJ_WEBP_DELETE_ORIGINAL',      true ); // false = move para backup
`

== Changelog ==

= 1.5.1 =
* Adicionados os campos "Requires at least" (WP 5.8) e "Requires PHP" (7.4) ao cabeçalho do plugin, para que os requisitos apareçam no instalador do WordPress.

= 1.5.0 =
* Adicionada atualização automática via GitHub Releases (biblioteca Plugin Update Checker). O painel passa a avisar e atualizar com um clique a cada novo Release publicado.

= 1.4.0 =
* Adicionado aviso no painel (admin notice) quando o servidor não tem suporte a WebP na GD (`imagewebp` ausente), deixando claro que a conversão está inativa.

= 1.3.0 =
* Limite de upload reduzido de 5 MB para 1 MB.
* Perfil de produto migrado para corte real por megapixels (2 MP, ~1400×1400) em vez de lado fixo.
* Adicionado bloqueio de fotos de produto não-quadradas (1:1, tolerância 2%).
* Fusível anti-bomba movido para o prefilter (recusa mais cedo, antes de processar).
* Redução progressiva passou a respeitar o piso de qualidade de cada contexto.
* Removida a limpeza de miniaturas (`cleanup_thumbnails`), que era inócua no fluxo normal e destrutiva em casos de falha.
* Corrigido vazamento de memória na rotação EXIF (`imagerotate`).
* `post_id` agora sanitizado com `absint()`.

= 1.2.0 =
* Inteligência de contexto (produto, logotipo, padrão).
* Output buffering para economia de memória.
* Fusível de 25 MP e backup do original.

== Upgrade Notice ==

= 1.3.0 =
Limite de upload agora é 1 MB e fotos de produto precisam ser quadradas. Revise seu fluxo de catálogo antes de atualizar.
