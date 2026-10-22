<meta name="x-ui" content="admin-polish">
{{-- 0.20.6: responsive + polish layer for the admin panel (no build step; plain CSS on top of Filament). --}}
<style>
/* Typography & rhythm */
.fi-body{-webkit-font-smoothing:antialiased;letter-spacing:-.003em}
.fi-header-heading{letter-spacing:-.02em}
.fi-section{border-radius:.85rem}
.fi-section-header-heading{font-size:.95rem;font-weight:650;letter-spacing:-.005em}
.fi-section-header{padding-block:.85rem}
.fi-fo-field-wrp-helper-text{font-size:.78rem;line-height:1.35}
.fi-badge{font-weight:600;letter-spacing:.01em}
.fi-ta-ctn{border-radius:.85rem}
.fi-ta-header-cell{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}
.fi-main{padding-bottom:2rem}
/* No sideways page scroll; wide tables scroll inside their own card */
html{overflow-x:hidden}
.fi-main,.fi-page{min-width:0;max-width:100%}
.fi-ta-content{overflow-x:auto;-webkit-overflow-scrolling:touch}
.fi-fo-repeater,.fi-fo-table-repeater{overflow-x:auto}
img,svg,video{max-width:100%}
/* Phones */
@media (max-width:640px){
  .fi-main{padding-inline:.6rem}
  .fi-header{gap:.5rem}
  .fi-header-heading{font-size:1.35rem}
  .fi-header .fi-ac{flex-wrap:wrap;width:100%}
  .fi-header .fi-ac>*{flex:1 1 auto}
  .fi-section-content{padding:.85rem}
  .fi-ta-cell{padding-block:.55rem}
  .fi-btn{min-height:2.5rem}
  .fi-input,.fi-select-input{font-size:16px} /* no iOS zoom on focus */
  .fi-modal-window{margin:.5rem;max-width:calc(100vw - 1rem)}
  .fi-tabs{overflow-x:auto;flex-wrap:nowrap}
}
</style>
<script>
    // Phones: start with the sidebar drawer closed so the page content is visible.
    document.addEventListener('alpine:initialized', () => {
        if (window.innerWidth < 1024 && window.Alpine && Alpine.store('sidebar')) {
            Alpine.store('sidebar').close();
        }
    });
</script>
