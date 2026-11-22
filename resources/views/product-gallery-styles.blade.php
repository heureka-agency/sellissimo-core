<style>
.ss-store .product-gallery [data-gallery-image] { position: relative; overflow: hidden; cursor: zoom-in; }
.ss-gallery-expand { position: absolute; inset-inline-end: .75rem; bottom: .75rem; display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: 50%; background: #16191be6; color: #fff; pointer-events: none; }
@media (any-hover: hover) and (any-pointer: fine) {
    .ss-store [data-hover-zoom] [data-hovering] img { transform: scale(2); transform-origin: var(--zoom-x, 50%) var(--zoom-y, 50%); }
}
html:has(.ss-gallery-dialog[open]) { overflow: hidden; }
.ss-gallery-dialog:not([open]) { display: none; }
.ss-gallery-dialog[open] {
    position: fixed; inset: 0; display: grid; grid-template: auto minmax(0, 1fr) / minmax(0, 1fr);
    margin: 0; padding: 0; border: 0; border-radius: 0; width: 100%; max-width: 100%;
    height: 100%; max-height: 100%; height: 100dvh; max-height: 100dvh;
    background: #16191b; color: #fff; overflow: hidden;
}
.ss-gallery-dialog::backdrop { background: #16191b; }
.ss-gallery-tools {
    display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem;
    padding: max(.5rem,env(safe-area-inset-top)) max(.75rem,env(safe-area-inset-right)) .5rem max(.75rem,env(safe-area-inset-left));
}
.ss-gallery-tools [data-gallery-count] { margin-inline-end: auto; font-variant-numeric: tabular-nums; }
.ss-store .ss-gallery-tools button {
    min-width: 2.75rem; min-height: 2.75rem; padding: .35rem .7rem;
    border: 1px solid #777d80; background: #202629; color: #fff; line-height: 1;
}
.ss-store .ss-gallery-tools button:hover, .ss-store .ss-gallery-tools [aria-pressed=true] { background: #fff; color: #141819; }
.ss-store .ss-gallery-tools :is([data-gallery-close],[data-gallery-next],[data-gallery-previous]) { font-size: 1.35rem; }
.ss-gallery-stage { overflow: auto; overscroll-behavior: contain; margin-bottom: env(safe-area-inset-bottom); }
.ss-gallery-canvas { width: 100%; height: 100%; }
.ss-store .product-gallery .ss-gallery-canvas img { display: block; width: 100%; height: 100%; min-height: 0; object-fit: contain; user-select: none; }
.ss-gallery-stage[data-zoomed] { cursor: grab; }
.ss-gallery-stage[data-zoomed]:active { cursor: grabbing; }
.ss-gallery-stage[data-zoomed] .ss-gallery-canvas { width: 200%; height: 200%; }
</style>
