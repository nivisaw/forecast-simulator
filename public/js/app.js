document.addEventListener('DOMContentLoaded', () => {
    const map = L.map('map').setView([-34.6037, -58.3816], 12); // Center of Buenos Aires

    // Dark mode tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    let zonesLayer = null;
    let selectedFeature = null;
    let activeZoneIds = new Set(); // Tracks zones included in simulation
    let optimizationResults = [];

    // UI Elements
    const loadingOverlay = document.getElementById('loading-overlay');
    const infoOverlay = document.getElementById('info-overlay');
    const zoneInfoContent = document.getElementById('selected-zone-info');
    const mockControls = document.getElementById('mock-controls');

    // Sliders
    const tempSlider = document.getElementById('mock-temp');
    const windSlider = document.getElementById('mock-wind');
    const tempVal = document.getElementById('temp-val');
    const windVal = document.getElementById('wind-val');

    const budgetSlider = document.getElementById('budget');
    const driversSlider = document.getElementById('drivers');
    const baseCostSlider = document.getElementById('base-cost');
    const budgetVal = document.getElementById('budget-val');
    const driversVal = document.getElementById('drivers-val');
    const baseCostVal = document.getElementById('base-cost-val');

    tempSlider.oninput = () => tempVal.textContent = tempSlider.value;
    windSlider.oninput = () => windVal.textContent = windSlider.value;
    budgetSlider.oninput = () => budgetVal.textContent = budgetSlider.value;
    driversSlider.oninput = () => driversVal.textContent = driversSlider.value;
    baseCostSlider.oninput = () => baseCostVal.textContent = baseCostSlider.value;

    // Automatic recalculation debounce
    let debounceTimeout;
    function debouncedRunOptimization() {
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(runOptimization, 300);
    }

    // Attach listeners
    ['budget', 'drivers', 'base-cost'].forEach(id => {
        document.getElementById(id).addEventListener('input', debouncedRunOptimization);
    });

    document.getElementById('mock-rain').addEventListener('change', debouncedRunOptimization);
    tempSlider.addEventListener('input', debouncedRunOptimization);
    windSlider.addEventListener('input', debouncedRunOptimization);

    let currentMode = 'real';
    let globalRemainingBudget = '$0.00';

    // Mode Switcher
    const modeTabs = document.querySelectorAll('.mode-tab');
    modeTabs.forEach(tab => {
        tab.onclick = () => {
            modeTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            currentMode = tab.dataset.mode;

            // Fix: ensure mockControls is hidden/shown correctly
            if (currentMode === 'simulation') {
                mockControls.classList.remove('hidden');
                mockControls.classList.add('visible');
            } else {
                mockControls.classList.remove('visible');
                mockControls.classList.add('hidden');
            }
            runOptimization();
        };
    });

    // Search and GPS
    const searchBtn = document.getElementById('search-btn');
    const gpsBtn = document.getElementById('gps-btn');
    const cityInput = document.getElementById('city-search');
    const autocompleteList = document.getElementById('autocomplete-list');

    function updateLocationModeUI(activeMode) {
        if (activeMode === 'search') {
            searchBtn.classList.remove('secondary-btn');
            searchBtn.classList.add('highlight-btn');
            gpsBtn.classList.remove('highlight-btn');
            gpsBtn.classList.add('secondary-btn');
        } else if (activeMode === 'gps') {
            gpsBtn.classList.remove('secondary-btn');
            gpsBtn.classList.add('highlight-btn');
            searchBtn.classList.remove('highlight-btn');
            searchBtn.classList.add('secondary-btn');
        }
    }

    let autocompleteTimeout;
    cityInput.addEventListener('input', (e) => {
        updateLocationModeUI('search');
        clearTimeout(autocompleteTimeout);
        const val = e.target.value;
        if (val.length < 3) {
            autocompleteList.classList.add('hidden');
            return;
        }
        autocompleteTimeout = setTimeout(() => fetchAutocomplete(val), 300);
    });

    searchBtn.onclick = () => {
        updateLocationModeUI('search');
        if (autocompleteList.children.length > 0) {
            autocompleteList.children[0].click(); // Pick first
        }
    };

    gpsBtn.onclick = () => {
        updateLocationModeUI('gps');
        getCurrentLocation();
    };

    const getApiUrl = (endpoint) => {
        let base = window.location.pathname;
        if (base.endsWith('index.php')) base = base.replace('index.php', '');
        if (!base.endsWith('/')) base += '/';
        return base + 'index.php/' + endpoint;
    };

    let currentCustomZones = null;

    async function fetchAutocomplete(query) {
        try {
            const response = await fetch(getApiUrl('search?q=' + encodeURIComponent(query)));
            const data = await response.json();

            autocompleteList.innerHTML = '';
            if (Array.isArray(data) && data.length > 0) {
                data.forEach(city => {
                    const li = document.createElement('li');
                    li.textContent = city.name;
                    li.onclick = () => {
                        cityInput.value = city.name;
                        autocompleteList.classList.add('hidden');
                        loadCityZones(city.lat, city.lon);
                    };
                    autocompleteList.appendChild(li);
                });
                autocompleteList.classList.remove('hidden');
            } else {
                autocompleteList.classList.add('hidden');
            }
        } catch (err) { console.error('Autocomplete error:', err); }
    }

    let cityBoundaryLayer = null;

    async function loadCityZones(lat, lon) {
        loadingOverlay.classList.remove('hidden');
        try {
            const response = await fetch(getApiUrl(`search?lat=${lat}&lon=${lon}`));
            const data = await response.json();

            if (data && data.zones) {
                if (zonesLayer) map.removeLayer(zonesLayer);
                if (cityBoundaryLayer) map.removeLayer(cityBoundaryLayer);

                // Add the real city boundary outline in green
                if (data.boundary) {
                    cityBoundaryLayer = L.geoJSON(data.boundary, {
                        style: {
                            color: '#22c55e', // Green border
                            weight: 3,
                            fillColor: 'transparent',
                            dashArray: '5, 5',
                            opacity: 0.8
                        },
                        interactive: false // Don't block clicks on the zones inside
                    }).addTo(map);
                }

                currentCustomZones = data.zones.features.map(f => ({
                    id: f.id,
                    name: f.properties.BARRIO,
                    geometry: f.geometry
                }));

                loadGeoJsonZones(data.zones);
                activeZoneIds.clear();
                selectedFeature = null;

                // Adjust bounds to avoid 300px info card on top-right
                setTimeout(() => {
                    if (cityBoundaryLayer) {
                        map.fitBounds(cityBoundaryLayer.getBounds(), { paddingTopRight: [320, 20] });
                    } else if (zonesLayer) {
                        map.fitBounds(zonesLayer.getBounds(), { paddingTopRight: [320, 20] });
                    }
                }, 300);

                runOptimization();
            } else {
                alert('Area not found');
            }
        } catch (err) {
            console.error('Zone load error:', err);
        } finally {
            loadingOverlay.classList.add('hidden');
        }
    }

    function getCurrentLocation() {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported by your browser');
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => loadCityZones(pos.coords.latitude, pos.coords.longitude),
            (err) => alert('Unable to retrieve location: ' + err.message)
        );
    }

    // Fetch zones and initialize map
    async function init() {
        // Try to get current location for start
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    updateLocationModeUI('gps');
                    loadCityZones(pos.coords.latitude, pos.coords.longitude);
                },
                () => loadZonesAndCalculate()
            );
        } else {
            loadZonesAndCalculate();
        }
    }

    async function loadZonesAndCalculate() {
        try {
            const response = await fetch(getApiUrl('zones'));
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const data = await response.json();
            if (data && data.type === 'FeatureCollection') {
                loadGeoJsonZones(data);
                setTimeout(() => {
                    if (zonesLayer) {
                        map.fitBounds(zonesLayer.getBounds(), { paddingTopRight: [320, 20] });
                    }
                }, 300);
                runOptimization();
            }
        } catch (err) {
            console.error('Failed to load zones:', err);
        }
    }

    function loadGeoJsonZones(data) {
        zonesLayer = L.geoJSON(data, {
            style: {
                color: '#38bdf8', weight: 2, fillOpacity: 0.1, fillColor: '#38bdf8'
            },
            onEachFeature: (feature, layer) => {
                layer.on('click', (e) => {
                    L.DomEvent.stopPropagation(e);
                    selectZone(feature, layer);
                });
            }
        }).addTo(map);
    }

    function showDefaultZoneInfo() {
        infoOverlay.classList.remove('hidden');
        zoneInfoContent.innerHTML = `
            Select a zone on the map to view detailed incentives.
        `;
    }

    function updateSelectedZoneInfo(feature) {
        const zoneId = feature.id || (feature.properties && (feature.properties.ID || feature.properties.cartodb_id));
        const name = feature.properties ? (feature.properties.BARRIO || feature.properties.nombre) : 'Zona';
        const result = optimizationResults.find(r => r.id === String(zoneId));

        infoOverlay.classList.remove('hidden');

        if (result) {
            const selectedZonesCount = activeZoneIds.size > 0 ? activeZoneIds.size : optimizationResults.length;
            const totalDrivers = parseInt(document.getElementById('drivers').value) || 0;
            const displayedDrivers = Math.floor(totalDrivers / Math.max(selectedZonesCount, 1));

            const w = result.weather;
            const bonoValue = (result.allocated_budget / Math.max(result.drivers, 1)).toFixed(0);
            const recValue = (parseFloat(document.getElementById('base-cost').value) + parseFloat(bonoValue)).toFixed(0);

            zoneInfoContent.innerHTML = `
                <div class="info-row"><strong>Zonas Seleccionadas:</strong> ${selectedZonesCount}</div>
                <div class="info-row"><strong>Conductores (aprox por zona):</strong> ${displayedDrivers}</div>
                <div class="info-row"><strong>Severidad Clima:</strong> ${(result.weather_score * 100).toFixed(0)}%</div>
                <div class="info-row"><strong>Escasez Conductores:</strong> ${(result.driver_score !== undefined ? (result.driver_score * 100).toFixed(0) : 0)}%</div>
                
                <div class="badge recommendation" style="display:block; margin-bottom:0.5rem; background:var(--accent);">
                    Bono Asignado: $${parseFloat(bonoValue).toLocaleString()} / pedido
                </div>
                <div class="badge recommendation" style="display:block;">
                    Recomendación Precio: $${parseFloat(recValue).toLocaleString()} / pedido
                </div>

                <div class="weather-stats">
                    <div class="stat-item">
                        <span class="icon">${w.is_raining ? '🌧️' : '☀️'}</span>
                        <span class="val">${w.is_raining ? 'Sí' : 'No'}</span>
                        <span class="unit">Lluvia</span>
                    </div>
                    <div class="stat-item">
                        <span class="icon">🌡️</span>
                        <span class="val">${w.temperature}°C</span>
                        <span class="unit">Temp</span>
                    </div>
                    <div class="stat-item">
                        <span class="icon">💨</span>
                        <span class="val">${w.wind_speed} km/h</span>
                        <span class="unit">Viento</span>
                    </div>
                </div>
                
                <div class="divider" style="margin: 1rem 0;"></div>
                <div class="stat-card" style="background: transparent; padding: 0; margin-top: 0;">
                    <span class="label" style="font-size: 1rem; color: var(--accent); display: block; margin-bottom: 0.25rem;">Remaining Budget</span>
                    <span class="value" style="font-size: 2.25rem; font-weight: 700; color: var(--text-main);">${globalRemainingBudget}</span>
                </div>
            `;
        } else {
            zoneInfoContent.innerHTML = `
                <div class="info-row"><strong>Zona:</strong> ${name}</div>
                <p class="text-muted">Calculando incentivos para esta zona...</p>
                <div class="loader-mini"></div>
            `;
        }
    }

    function selectZone(feature, layer) {
        // Toggle selection
        const zoneId = feature.id || (feature.properties && (feature.properties.ID || feature.properties.cartodb_id));

        if (activeZoneIds.has(zoneId)) {
            activeZoneIds.delete(zoneId);
            if (selectedFeature === layer) {
                selectedFeature = null;
            }
        } else {
            activeZoneIds.add(zoneId);
            selectedFeature = layer; // last clicked becomes the info-card focus
        }

        // Update colors based on current active list immediately
        updateMapColors();

        if (selectedFeature) {
            updateSelectedZoneInfo(selectedFeature.feature);
        } else {
            showDefaultZoneInfo();
        }

        // ── Mobile: close sidebar & expand info panel on zone tap ──
        if (window.innerWidth < 768) {
            const _sidebar   = document.querySelector('.sidebar');
            const _scrim     = document.getElementById('scrim');
            const _toggleBtn = document.getElementById('sidebar-toggle');
            const _infoEl    = document.getElementById('info-overlay');
            const _peekBar   = document.getElementById('panel-peek-bar');

            if (_sidebar)   { _sidebar.classList.remove('sidebar-open'); _sidebar.classList.remove('sidebar-active'); }
            if (_scrim)     { _scrim.classList.remove('visible'); }
            if (_toggleBtn) { _toggleBtn.classList.remove('open'); _toggleBtn.setAttribute('aria-expanded', 'false'); }
            if (_infoEl)    {
                _infoEl.classList.remove('sidebar-active');
                if (selectedFeature) { _infoEl.classList.add('panel-expanded'); }
                else                 { _infoEl.classList.remove('panel-expanded'); }
            }
            if (_peekBar)   { _peekBar.setAttribute('aria-expanded', selectedFeature ? 'true' : 'false'); }

            setTimeout(() => map.invalidateSize(), 360);
        }

        // Trigger recalculation since active zones changed
        debouncedRunOptimization();
    }

    async function runOptimization() {
        if (!loadingOverlay.classList.contains('hidden')) return; // Avoid double run
        loadingOverlay.classList.remove('hidden');

        const center = map.getCenter();
        // Build custom zones from activeZoneIds if user has toggled any.
        // If none is toggled, default to sending current custom zones (e.g. from search)
        let simulationZones = currentCustomZones;
        if (activeZoneIds.size > 0 && zonesLayer) {
            simulationZones = [];
            zonesLayer.eachLayer(layer => {
                const zId = layer.feature.id || (layer.feature.properties && (layer.feature.properties.ID || layer.feature.properties.cartodb_id));
                if (activeZoneIds.has(zId)) {
                    simulationZones.push({
                        id: zId,
                        name: layer.feature.properties ? (layer.feature.properties.BARRIO || layer.feature.properties.nombre) : 'Zona',
                        geometry: layer.feature.geometry
                    });
                }
            });
        }

        const payload = {
            budget: parseFloat(document.getElementById('budget').value) || 0,
            drivers: parseInt(document.getElementById('drivers').value) || 1,
            base_cost: parseFloat(document.getElementById('base-cost').value) || 0,
            use_mock: currentMode === 'simulation',
            mock_weather: {
                is_raining: document.getElementById('mock-rain').checked,
                temperature: parseFloat(tempSlider.value),
                wind_speed: parseFloat(windSlider.value),
                humidity: 60
            },
            custom_zones: simulationZones,
            fallback_zone: {
                name: cityInput.value || 'Current Location',
                lat: center.lat,
                lon: center.lng,
                id: 'global-fallback'
            }
        };

        try {
            const response = await fetch(getApiUrl('calculate'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            if (result.status === 'success') {
                optimizationResults = result.data;
                const formattedBudget = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(result.remaining_budget);
                globalRemainingBudget = formattedBudget;
                updateMapColors();

                // Refresh selected zone display if one is active
                if (selectedFeature) {
                    updateSelectedZoneInfo(selectedFeature.feature);
                } else {
                    showDefaultZoneInfo();
                }
            } else {
                console.error('Optimization error:', result.message);
            }
        } catch (err) {
            console.error('Optimization failed:', err);
        } finally {
            loadingOverlay.classList.add('hidden');
        }
    }

    function updateMapColors() {
        if (!zonesLayer) return;

        zonesLayer.eachLayer(layer => {
            const zoneId = layer.feature.id || (layer.feature.properties && (layer.feature.properties.ID || layer.feature.properties.cartodb_id));
            const res = optimizationResults.find(r => r.id === String(zoneId));

            let isActive = activeZoneIds.has(zoneId);

            if (res && isActive) {
                // Color based on weather score: Blue -> Yellow -> Red
                const score = res.weather_score;
                let color = '#38bdf8';
                if (score > 0.3) color = '#fbbf24';
                if (score > 0.6) color = '#ef4444';

                if (layer === selectedFeature) {
                    layer.setStyle({
                        fillColor: color,
                        fillOpacity: 0.8,
                        weight: 4,
                        color: '#fff'
                    });
                    layer.bringToFront();
                } else {
                    layer.setStyle({
                        fillColor: color,
                        fillOpacity: 0.4 + (score * 0.4),
                        color: color,
                        weight: 2
                    });
                }
            } else {
                // Not active (forma el croquis de la ciudad)
                layer.setStyle({
                    fillColor: '#94a3b8',
                    fillOpacity: 0.05,
                    color: '#cbd5e1',
                    weight: 1.5,
                    dashArray: '4'
                });
            }
        });
    }

    // ================================================================
    // MOBILE RESPONSIVE CONTROLS
    // ── Sidebar bottom sheet + info peek panel (ui-ux-pro-max §5, §9)
    // ================================================================

    const sidebarToggleBtn = document.getElementById('sidebar-toggle');
    const scrimEl          = document.getElementById('scrim');
    const sidebarEl        = document.querySelector('.sidebar');
    const infoOverlayEl    = document.getElementById('info-overlay');
    const panelPeekBar     = document.getElementById('panel-peek-bar');

    /** Opens the sidebar sheet (mobile) */
    function openMobileSidebar() {
        sidebarEl.classList.add('sidebar-open');
        scrimEl.classList.add('visible');
        sidebarToggleBtn.classList.add('open');
        sidebarToggleBtn.setAttribute('aria-expanded', 'true');
        // Push info panel below so it doesn't overlap
        infoOverlayEl.classList.add('sidebar-active');
        infoOverlayEl.classList.remove('panel-expanded');
    }

    /** Closes the sidebar sheet (mobile) */
    function closeMobileSidebar() {
        sidebarEl.classList.remove('sidebar-open');
        scrimEl.classList.remove('visible');
        sidebarToggleBtn.classList.remove('open');
        sidebarToggleBtn.setAttribute('aria-expanded', 'false');
        infoOverlayEl.classList.remove('sidebar-active');
        // Re-trigger map resize after animation
        setTimeout(() => map.invalidateSize(), 370);
    }

    // Hamburger button click
    if (sidebarToggleBtn) {
        sidebarToggleBtn.addEventListener('click', () => {
            if (sidebarEl.classList.contains('sidebar-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        });
    }

    // Scrim click → close sidebar
    if (scrimEl) {
        scrimEl.addEventListener('click', closeMobileSidebar);
    }

    // Panel peek bar → toggle expand/collapse
    if (panelPeekBar) {
        const togglePanel = () => {
            const isExpanded = infoOverlayEl.classList.toggle('panel-expanded');
            panelPeekBar.setAttribute('aria-expanded', String(isExpanded));
        };
        panelPeekBar.addEventListener('click', togglePanel);
        panelPeekBar.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePanel(); }
        });
    }

    // Swipe-down to close sidebar sheet
    let _touchStartYSidebar = 0;
    if (sidebarEl) {
        sidebarEl.addEventListener('touchstart', (e) => {
            _touchStartYSidebar = e.touches[0].clientY;
        }, { passive: true });

        sidebarEl.addEventListener('touchend', (e) => {
            const deltaY = e.changedTouches[0].clientY - _touchStartYSidebar;
            if (deltaY > 70 && window.innerWidth < 768) { closeMobileSidebar(); }
        }, { passive: true });
    }

    // Swipe-down to collapse info panel
    let _touchStartYPanel = 0;
    if (infoOverlayEl) {
        infoOverlayEl.addEventListener('touchstart', (e) => {
            _touchStartYPanel = e.touches[0].clientY;
        }, { passive: true });

        infoOverlayEl.addEventListener('touchend', (e) => {
            const deltaY = e.changedTouches[0].clientY - _touchStartYPanel;
            if (deltaY > 55 && window.innerWidth < 768) {
                infoOverlayEl.classList.remove('panel-expanded');
                panelPeekBar && panelPeekBar.setAttribute('aria-expanded', 'false');
            }
        }, { passive: true });
    }

    // On resize from mobile → desktop: reset sidebar state
    let _prevWidth = window.innerWidth;
    window.addEventListener('resize', () => {
        const w = window.innerWidth;
        if (_prevWidth < 768 && w >= 768) {
            sidebarEl.classList.remove('sidebar-open', 'sidebar-active');
            scrimEl.classList.remove('visible');
            sidebarToggleBtn.classList.remove('open');
            infoOverlayEl.classList.remove('sidebar-active', 'panel-expanded');
            setTimeout(() => map.invalidateSize(), 100);
        }
        _prevWidth = w;
    });

    init();
});
