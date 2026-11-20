<?php
return static fn ($request, $params, $context) => new ZoltaGaska\Frontend\PageData(
    ['csrfToken' => 'forged', 'params' => ['id' => 'forged']], $context->title, $context->description, '/offer?view=1&v=2');
