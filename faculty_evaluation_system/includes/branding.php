<?php

function systemImagePath(string $image): string
{
    $images = [
        'campus' => '468365392_550426124407386_550347314913598589_n.jpg',
        'isufst' => 'ISUFST.jpg',
        'logo' => 'OIP.jfif',
        'graduation' => 'JQrqEuPrzXBuYJHnjxO-WqW5I9ZhINAhPb4AfIgzFprT_s3DW4tWSRl0aUGU1GscBuMfR_9OGn0KxQhahH6ypfHpoOIDYzQOan5kbb3foW05hQniQrkdUx9bgLG3_R3I4JjXL9-oBpCSR4Lexvaiv6Fy_t-d7FSBzIeU6AsEUuOpc4A82CocjYINsLxJQA0h.jfif',
        'blue' => 'pngtree-blue-background-photos-download-free-wallpaper-hd-dynamic-colorful-guardian-texture-picture-image_2712471.jpg',
    ];

    if (!isset($images[$image])) {
        throw new InvalidArgumentException('Unknown system image.');
    }

    return appPath('assets/images/' . $images[$image]);
}
