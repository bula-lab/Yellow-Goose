<?php
namespace ZoltaGaska\Frontend;

interface Renderer
{
    public function render(string $page, array $props): string;
}
