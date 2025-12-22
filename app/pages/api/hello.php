<?php

// Prosty endpoint API
return function () {
    return [
        'time' => (new DateTime())->format('H:i:s')
    ];
};
