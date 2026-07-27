import { Controller } from '@hotwired/stimulus';

/*
 * Initialisiert den Video.js-Player und steuert:
 * - Fehleranzeige
 * - automatischen Sprung zum ?t= Zeitstempel aus der URL (inkl. Autoplay)
 * - Sprung zu einem Kapitel-Zeitstempel per Button (jumpTo)
 * - Übernahme der aktuellen Player-Zeit beim Thumbnail-Export
 *
 * Video.js selbst wird per CDN (<script>) geladen und global als
 * window.videojs bereitgestellt.
 */
export default class extends Controller {
    static targets = ['player', 'errorMessage', 'thumbnailInput'];

    connect() {
        this.player = null;

        if (typeof window.videojs === 'undefined' || !this.hasPlayerTarget) {
            return;
        }

        this.player = window.videojs(this.playerTarget, {
            aspectRatio: '16:9',
            playbackRates: [0.5, 1, 1.5, 2],
            controlBar: {
                children: [
                    'playToggle',
                    'volumePanel',
                    'currentTimeDisplay',
                    'timeDivider',
                    'durationDisplay',
                    'progressControl',
                    'liveDisplay',
                    'remainingTimeDisplay',
                    'playbackRateMenuButton',
                    'chaptersButton',
                    'descriptionsButton',
                    'subsCapsButton',
                    'audioTrackButton',
                    'fullscreenToggle',
                ],
            },
        });

        this.player.on('error', () => this.showError());
        this.player.ready(() => this.jumpToUrlTime());
    }

    disconnect() {
        if (this.player) {
            this.player.dispose();
            this.player = null;
        }
    }

    showError() {
        const error = this.player.error();
        if (this.hasErrorMessageTarget && error) {
            this.errorMessageTarget.classList.remove('d-none');
            const details = this.errorMessageTarget.querySelector('.error-details');
            if (details) {
                details.textContent = error.message + ' (Code: ' + error.code + ')';
            }
        }
    }

    jumpToUrlTime() {
        const urlParams = new URLSearchParams(window.location.search);
        const startTime = urlParams.get('t');

        if (startTime === null) {
            return;
        }

        const time = parseFloat(startTime);
        if (isNaN(time)) {
            return;
        }

        let hasJumped = false;
        const jump = () => {
            if (hasJumped) {
                return;
            }
            hasJumped = true;
            this.player.currentTime(time);
            // Stummschalten, um Browser-Autoplay-Richtlinien zu erfüllen
            this.player.muted(true);
            const playPromise = this.player.play();
            if (playPromise && typeof playPromise.catch === 'function') {
                playPromise.catch((e) => console.error('Play failed:', e));
            }
        };

        // Das Medium muss seekbar sein. "canplay" bzw. ein geladener
        // readyState garantiert dies für MP4 und HLS.
        if (this.player.readyState() >= 1) {
            jump();
        } else {
            this.player.one('loadedmetadata', jump);
            this.player.one('canplay', jump);
        }
    }

    jumpTo(event) {
        if (!this.player) {
            return;
        }
        const time = parseFloat(event.params.time);
        if (isNaN(time)) {
            return;
        }
        this.player.currentTime(time);
        this.player.play();
        this.player.el().scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    prepareThumbnail() {
        if (!this.hasThumbnailInputTarget) {
            return;
        }
        if (this.player && this.player.currentTime) {
            // Zeit minimal erhöhen, damit ffmpeg ein Frame an dieser Stelle
            // findet und nicht das vorherige Keyframe nimmt.
            let currentTime = this.player.currentTime();
            if (currentTime > 0) {
                currentTime += 0.1;
            }
            this.thumbnailInputTarget.value = currentTime;
        } else {
            this.thumbnailInputTarget.value = 0;
        }
    }
}
