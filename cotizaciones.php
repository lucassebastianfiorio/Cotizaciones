<?php
/*
Plugin Name: Cotizaciones
Description: Muestra cotizaciones específicas de dólares y reales. Ambos oficiales y blue con respecto al peso argentino.
Version: 1.6
Author: Lucas S. Fiorio
*/

// Evitar acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

// Agregar enlace en la página de administración del plugin
function agregar_enlace_modal() {
    echo '<div class="wrap">';
    echo '<h1>Plugin Cotizaciones</h1>';
    echo '<h2>Instrucciones de Uso</h2>';
    echo '<p>Este plugin muestra cotizaciones específicas de diferentes monedas. A continuación se detallan los pasos para utilizarlo:</p>';
    echo '<ol>';
    echo '<li><strong>Instalación:</strong> Sube el archivo del plugin a la carpeta <code>wp-content/plugins</code> de tu instalación de WordPress y actívalo desde el panel de administración de plugins.</li>';
    echo '<li><strong>Uso:</strong> Para mostrar las cotizaciones en cualquier parte de tu sitio, utiliza uno de los siguientes shortcodes en la página o entrada donde deseas mostrar la información:</li>';
    echo '<ul>';
    echo '<li><code>[cotizaciones]</code>: Muestra todas las cotizaciones disponibles, incluyendo Dólar Oficial, Dólar Blue, Dólar Cripto, Dólar Tarjeta, Reales, Guaraníes y Real Blue. Este es el shortcode predeterminado.</li>';
    echo '</ul>';
    echo '<li><strong>Configuración:</strong> Puedes ajustar los porcentajes para cada moneda en la página de configuración del plugin.</li>';
    echo '</ol>';
    echo '</div>';
}
add_action('admin_menu', function() {
    add_menu_page('Cotizaciones', 'Cotizaciones', 'manage_options', 'cotizaciones', 'agregar_enlace_modal');
});

// Función para calcular el Real Blue
function calcular_real_blue($cotizaciones) {
    $dolar_blue = null;
    $dolar_oficial = null;
    $real_oficial = null;

    foreach ($cotizaciones as $cotizacion) {
        switch ($cotizacion['nombre']) {
            case 'Dólar Blue':
                $dolar_blue = $cotizacion['venta'];
                break;
            case 'Dólar Oficial':
                $dolar_oficial = $cotizacion['venta'];
                break;
            case 'Real (Of)':
                $real_oficial = $cotizacion['venta'];
                break;
        }
    }

    if ($dolar_blue && $dolar_oficial && $real_oficial) {
        $real_blue = ($dolar_blue / $dolar_oficial) * $real_oficial;
        
        $cotizaciones[] = array(
            'nombre' => 'Real Blue',
            'moneda' => 'BRL',
            'compra' => $real_blue,
            'venta' => $real_blue,
            'fechaActualizacion' => date('Y-m-d H:i:s')
        );
    }

    return $cotizaciones;
}

// Función para obtener cotizaciones
function obtener_cotizaciones() {
    $api_key = get_option('cotizaciones_api_key', '');
    if (empty($api_key)) {
        return false; // API key no configurada
    }

    $url_dolares = 'https://dolarapi.com/v1/dolares?api_key=' . $api_key;
    $url_real = 'https://dolarapi.com/v1/cotizaciones/brl?api_key=' . $api_key;

    $response_dolares = wp_remote_get($url_dolares);
    $response_real = wp_remote_get($url_real);

    if (is_wp_error($response_dolares) || is_wp_error($response_real)) {
        return false;
    }

    $body_dolares = wp_remote_retrieve_body($response_dolares);
    $body_real = wp_remote_retrieve_body($response_real);

    $data_dolares = json_decode($body_dolares, true);
    $data_real = json_decode($body_real, true);

    // Filtrar solo las cotizaciones deseadas
    $cotizaciones_deseadas = [
        'Oficial' => 'Dólar Oficial',
        'Blue' => 'Dólar Blue',
        'Cripto' => 'Dólar Cripto',
        'Tarjeta' => 'Dólar Tarjeta',
    ];

    $filtered_cotizaciones = [];
    foreach ($cotizaciones_deseadas as $key => $nombre) {
        foreach ($data_dolares as $cotizacion) {
            if ($cotizacion['nombre'] === $key) {
                $filtered_cotizaciones[] = array(
                    'nombre' => $nombre,
                    'moneda' => $cotizacion['moneda'],
                    'compra' => $cotizacion['compra'],
                    'venta' => $cotizacion['venta'],
                    'fechaActualizacion' => $cotizacion['fechaActualizacion']
                );
                break;
            }
        }
    }

    // Añadir el real al array de cotizaciones
    $filtered_cotizaciones[] = array(
        'nombre' => 'Real (Of)',
        'moneda' => 'BRL',
        'compra' => $data_real['compra'],
        'venta' => $data_real['venta'],
        'fechaActualizacion' => $data_real['fechaActualizacion']
    );

    // Calcular y añadir el Real Blue
    $filtered_cotizaciones = calcular_real_blue($filtered_cotizaciones);

    $porcentajes = get_option('cotizaciones_porcentajes', array());

    foreach ($filtered_cotizaciones as $index => &$cotizacion) {
        $porcentaje = isset($porcentajes[$index]) ? $porcentajes[$index] : 0;
        $factor = 1 + ($porcentaje / 100);
        $cotizacion['compra'] *= $factor;
        $cotizacion['venta'] *= $factor;
    }

    // Definir el orden deseado
    $orden_deseado = [
        'Dólar Oficial',
        'Dólar Blue',
        'Dólar Tarjeta',
        'Dólar Cripto',
        'Real (Of)',
        'Real Blue'
    ];

    // Ordenar las cotizaciones según el orden deseado
    usort($filtered_cotizaciones, function($a, $b) use ($orden_deseado) {
        return array_search($a['nombre'], $orden_deseado) - array_search($b['nombre'], $orden_deseado);
    });

    return $filtered_cotizaciones;
}

// Función para el shortcode
function cotizaciones_shortcode($atts) {
    $atts = shortcode_atts(
        array(
            'mostrar' => 'venta'
        ), $atts, 'cotizaciones');

    $output = '<div class="cotizaciones-container">';

    // Obtener y mostrar cotizaciones
    $cotizaciones = obtener_cotizaciones();
    if ($cotizaciones) {
        foreach ($cotizaciones as $cotizacion) {
            $output .= formato_cotizacion($cotizacion, $atts['mostrar']);
        }
    } else {
        $output .= '<div class="cotizacion-card">Error al obtener las cotizaciones.</div>';
    }

    $output .= '</div>';
    return $output;
}

// Función auxiliar para formatear la salida de cada cotización
function formato_cotizacion($cotizacion, $mostrar) {
    $nombre = $cotizacion['nombre'];
    $valor = number_format($cotizacion[$mostrar], 2, '.', ',');
    $fecha = date('d/m/Y H:i', strtotime($cotizacion['fechaActualizacion']));
    return "<div class='cotizacion-card'><strong>$nombre:</strong><div class='valor'> $ $valor </div></div>";
}

// Registrar shortcode
add_shortcode('cotizaciones', 'cotizaciones_shortcode');

// Agregar estilos CSS
function agregar_estilos_cotizaciones() {
    echo '
    <style>
 .cotizaciones-container {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        justify-content: center;
    }
    .cotizacion-card {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px; 
        width: calc(100% / 6 - 20px); 
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        background-color: #f9f9f9;
        text-align: center;
        box-sizing: border-box;
    }
    .cotizacion-card strong {
        display: block;
        font-size: 0.9em; 
        margin-bottom: 8px; 
    }
    .cotizacion-card .fecha-actualizacion {
        font-size: 0.8em; 
        color: #555;
    }
    .valor {
        font-size: 18px; 
        font-weight: bold;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .cotizacion-card {
            width: calc(100% / 2 - 20px); 
            padding: 10px; 
        }
        .cotizacion-card strong {
            font-size: 0.8em; 
            margin-bottom: 6px; 
        }
        .cotizacion-card .fecha-actualizacion {
            font-size: 0.7em; 
        }
        .valor {
            font-size: 16px; 
        }
    }
    </style>
    ';
}
add_action('wp_head', 'agregar_estilos_cotizaciones');
?>
