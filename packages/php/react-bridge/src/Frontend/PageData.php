<?php
namespace ZoltaGaska\Frontend;

/** A loader can opt into per-page document metadata alongside its ordinary props. */
final class PageData
{
    public function __construct(
        public readonly array $props,
        private ?string $title = null,
        private ?string $description = null,
        private ?string $canonical = null,
    ) {
        if ($canonical !== null) {
            $parts = parse_url($canonical);
            $local = str_starts_with($canonical, '/') && !str_starts_with($canonical, '//');
            $absolute = is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
            if ((!$local && !$absolute) || preg_match('/[\x00-\x20\x7f\\\\]/', $canonical)) {
                throw new \InvalidArgumentException('Invalid canonical URL.');
            }
        }
    }

    public function head(string $defaultTitle): array
    {
        return array_filter(['title' => $this->title ?? $defaultTitle, 'description' => $this->description,
            'canonical' => $this->canonical], static fn ($value) => $value !== null);
    }
}
