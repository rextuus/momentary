import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

/*
 * Karte mit Videos und Fotos (OpenStreetMap-Kacheln, gruppierte Marker).
 * Die Punkte kommen als JSON von url-Value; ein Klick öffnet eine Vorschau mit Link zum Medium.
 */
export default class extends Controller {
    static values = { url: String, zoom: { type: Number, default: 13 } };
    static targets = ['map', 'empty'];

    async connect() {
        // Das Cluster-Plugin erweitert das globale L
        window.L = L;
        await import('leaflet.markercluster');

        const element = this.hasMapTarget ? this.mapTarget : this.element;
        this.map = L.map(element, { scrollWheelZoom: true });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(this.map);

        const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
        const points = response.ok ? await response.json() : [];
        if (points.length === 0) {
            this.map.setView([51.16, 10.45], 5);
            if (this.hasEmptyTarget) {
                this.emptyTarget.classList.remove('d-none');
            }
            return;
        }

        const cluster = L.markerClusterGroup({ showCoverageOnHover: false, maxClusterRadius: 50 });
        for (const point of points) {
            const marker = L.marker([point.lat, point.lon], {
                icon: L.divIcon({
                    className: 'media-marker',
                    html: `<i class="fas ${point.type === 'video' ? 'fa-video' : 'fa-image'}"></i>`,
                    iconSize: [30, 30],
                }),
                title: point.title ?? '',
            });
            marker.bindPopup(this.popup(point), { minWidth: 200 });
            cluster.addLayer(marker);
        }
        this.map.addLayer(cluster);

        if (points.length === 1) {
            this.map.setView([points[0].lat, points[0].lon], this.zoomValue);
        } else {
            this.map.fitBounds(cluster.getBounds(), { padding: [30, 30], maxZoom: 15 });
        }
    }

    disconnect() {
        this.map?.remove();
        this.map = null;
    }

    popup(point) {
        const container = document.createElement('a');
        container.href = point.url;
        container.className = 'text-decoration-none text-body d-block';
        if (point.thumbnail) {
            const img = document.createElement('img');
            img.src = point.thumbnail;
            img.alt = '';
            img.className = 'rounded mb-1 d-block';
            img.style.width = '200px';
            container.appendChild(img);
        }
        const title = document.createElement('div');
        title.className = 'fw-semibold';
        title.textContent = point.title ?? '';
        container.appendChild(title);
        const meta = document.createElement('div');
        meta.className = 'small text-muted';
        meta.textContent = [point.date, point.place].filter(Boolean).join(' · ');
        container.appendChild(meta);

        return container;
    }
}
