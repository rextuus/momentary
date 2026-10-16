import { Controller } from '@hotwired/stimulus';

/*
 * Zeichnet einen Gesichtsausschnitt (Vollbild + relative Bounding Box) auf ein <canvas>.
 *
 * Interaktiv (interactive=true):
 *   Mausrad = zoomen (zum Mauszeiger), Ziehen = verschieben, Doppelklick = zurücksetzen.
 *
 * Der Zustand liegt im Controller bzw. in den Canvas-Pixeln, Live-Component-Re-Renders
 * fassen beides nicht an. Nur wenn sich src/box ändern (anderes Gesicht), wird neu geladen.
 */
export default class extends Controller {
    static targets = ['canvas'];
    static values = {
        src: String,
        box: Object, // { left, top, width, height } als Anteile (0..1)
        padding: { type: Number, default: 2.6 },
        interactive: { type: Boolean, default: false },
    };

    initialize() {
        this.img = null;
        this.loading = false;
        this.cx = 0;
        this.cy = 0;
        this.side = 1;
        this.baseSide = 1;
        this.drag = null;
        this.raf = null;
        this.boundCanvas = null;
    }

    connect() {
        if (this.interactiveValue && this.hasCanvasTarget) {
            const canvas = this.canvasTarget;
            this.boundCanvas = canvas;
            this.onWheel = (e) => this.handleWheel(e);
            this.onDown = (e) => this.handleDown(e);
            this.onMove = (e) => this.handleMove(e);
            this.onUp = (e) => this.handleUp(e);
            this.onDbl = () => this.reset();
            canvas.addEventListener('wheel', this.onWheel, { passive: false });
            canvas.addEventListener('pointerdown', this.onDown);
            canvas.addEventListener('pointermove', this.onMove);
            canvas.addEventListener('pointerup', this.onUp);
            canvas.addEventListener('pointercancel', this.onUp);
            canvas.addEventListener('dblclick', this.onDbl);
        }
        this.requestDraw();
    }

    disconnect() {
        const canvas = this.boundCanvas;
        if (canvas) {
            canvas.removeEventListener('wheel', this.onWheel);
            canvas.removeEventListener('pointerdown', this.onDown);
            canvas.removeEventListener('pointermove', this.onMove);
            canvas.removeEventListener('pointerup', this.onUp);
            canvas.removeEventListener('pointercancel', this.onUp);
            canvas.removeEventListener('dblclick', this.onDbl);
            this.boundCanvas = null;
        }
        if (this.raf) {
            cancelAnimationFrame(this.raf);
            this.raf = null;
        }
    }

    // ── Stimulus Value-Callbacks (src zuerst deklariert -> läuft vor box) ──

    srcValueChanged() {
        this.loadImage();
    }

    boxValueChanged() {
        if (this.img && !this.loading) {
            this.resetView();
            this.requestDraw();
        }
    }

    // ── Actions (Buttons) ──

    zoomIn() {
        this.zoomBy(1.5);
    }

    zoomOut() {
        this.zoomBy(1 / 1.5);
    }

    reset() {
        this.resetView();
        this.requestDraw();
    }

    // ── Intern ──

    loadImage() {
        const src = this.srcValue;
        this.img = null;

        if (!src) {
            this.loading = false;
            this.requestDraw();
            return;
        }

        this.loading = true;
        this.requestDraw();

        const img = new Image();
        img.onload = () => {
            if (src !== this.srcValue) {
                return;
            }
            this.img = img;
            this.loading = false;
            this.resetView();
            this.requestDraw();
        };
        img.onerror = () => {
            if (src === this.srcValue) {
                this.loading = false;
                this.img = null;
                this.requestDraw();
            }
        };
        img.src = src;
    }

    resetView() {
        if (!this.img) {
            return;
        }

        const W = this.img.naturalWidth;
        const H = this.img.naturalHeight;
        const b = this.boxValue || {};

        if (typeof b.width === 'number' && b.width > 0 && typeof b.height === 'number') {
            const bw = b.width * W;
            const bh = b.height * H;
            this.cx = (b.left + b.width / 2) * W;
            this.cy = (b.top + b.height / 2) * H;
            this.baseSide = Math.max(Math.max(bw, bh) * this.paddingValue, 48);
        } else {
            this.cx = W / 2;
            this.cy = H / 2;
            this.baseSide = Math.min(W, H);
        }

        this.side = this.baseSide;
        this.clampCenter();
    }

    zoomBy(factor, u = 0.5, v = 0.5) {
        if (!this.img) {
            return;
        }

        const maxSide = Math.max(this.img.naturalWidth, this.img.naturalHeight) * 1.2;
        const newSide = Math.min(maxSide, Math.max(24, this.side / factor));

        // Bildpunkt unter dem Zeiger bleibt unter dem Zeiger
        const px = this.cx + (u - 0.5) * this.side;
        const py = this.cy + (v - 0.5) * this.side;

        this.side = newSide;
        this.cx = px - (u - 0.5) * newSide;
        this.cy = py - (v - 0.5) * newSide;

        this.clampCenter();
        this.requestDraw();
    }

    clampCenter() {
        if (!this.img) {
            return;
        }
        this.cx = Math.min(this.img.naturalWidth, Math.max(0, this.cx));
        this.cy = Math.min(this.img.naturalHeight, Math.max(0, this.cy));
    }

    handleWheel(event) {
        event.preventDefault();
        const rect = this.boundCanvas.getBoundingClientRect();
        const u = (event.clientX - rect.left) / rect.width;
        const v = (event.clientY - rect.top) / rect.height;
        const delta = Math.max(-300, Math.min(300, event.deltaY));
        this.zoomBy(Math.exp(-delta * 0.002), u, v);
    }

    handleDown(event) {
        if (event.button !== 0 || !this.img) {
            return;
        }
        this.drag = { x: event.clientX, y: event.clientY };
        this.boundCanvas.setPointerCapture(event.pointerId);
        this.boundCanvas.style.cursor = 'grabbing';
    }

    handleMove(event) {
        if (!this.drag) {
            return;
        }
        const rect = this.boundCanvas.getBoundingClientRect();
        const k = this.side / rect.width;
        this.cx -= (event.clientX - this.drag.x) * k;
        this.cy -= (event.clientY - this.drag.y) * k;
        this.drag = { x: event.clientX, y: event.clientY };
        this.clampCenter();
        this.requestDraw();
    }

    handleUp(event) {
        if (!this.drag) {
            return;
        }
        this.drag = null;
        try {
            this.boundCanvas.releasePointerCapture(event.pointerId);
        } catch (e) {
            // ignorieren
        }
        this.boundCanvas.style.cursor = 'grab';
    }

    requestDraw() {
        if (this.raf) {
            return;
        }
        this.raf = requestAnimationFrame(() => this.draw());
    }

    draw() {
        this.raf = null;
        if (!this.hasCanvasTarget) {
            return;
        }

        const canvas = this.canvasTarget;
        const ctx = canvas.getContext('2d');
        const size = canvas.width;

        ctx.fillStyle = '#111';
        ctx.fillRect(0, 0, size, size);

        if (!this.img) {
            return;
        }

        const W = this.img.naturalWidth;
        const H = this.img.naturalHeight;
        const sx = this.cx - this.side / 2;
        const sy = this.cy - this.side / 2;
        const k = size / this.side;

        // Quellbereich selbst auf das Bild beschneiden (browserübergreifend konsistent)
        const x0 = Math.max(0, sx);
        const y0 = Math.max(0, sy);
        const x1 = Math.min(W, sx + this.side);
        const y1 = Math.min(H, sy + this.side);
        if (x1 <= x0 || y1 <= y0) {
            return;
        }

        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(
            this.img,
            x0, y0, x1 - x0, y1 - y0,
            (x0 - sx) * k, (y0 - sy) * k, (x1 - x0) * k, (y1 - y0) * k
        );
    }
}