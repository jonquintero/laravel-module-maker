<?php

return [
    // Dónde se crearán los módulos dentro del PROYECTO que usa el paquete
    'modules_path' => base_path('modules'),

    // Dónde buscar stubs primero en el PROYECTO (si fueron publicados)
    // Si no existen, el comando usará los stubs internos del paquete.
    'stubs_path' => base_path('stubs/vendor/module-maker/module'),
];
