<?php

return [
    // D�nde se crear�n los m�dulos dentro del PROYECTO que usa el paquete
    'modules_path' => base_path('modules'),

    // D�nde buscar stubs primero en el PROYECTO (si fueron publicados)
    // Si no existen, el comando usar� los stubs internos del paquete.
    'stubs_path' => base_path('stubs/vendor/module-maker/module'),
];
