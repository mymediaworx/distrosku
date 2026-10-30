{{-- #10: shared design tokens (Company Settings > Brand > Brand color). Layouts use var(--brand), var(--on-brand), var(--ok), var(--danger), var(--font). --}}
<style>{!! \App\Support\Tokens::css($default ?? \App\Support\Tokens::DEFAULT_BRAND) !!}</style>
