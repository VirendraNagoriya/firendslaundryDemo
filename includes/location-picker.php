<?php
declare(strict_types=1);

function google_maps_api_key(): string
{
    return trim(setting('google_maps_api_key', ''));
}

/**
 * Read a latitude/longitude/place_id trio from the POST body.
 *
 * @return array{latitude:?float, longitude:?float, place_id:?string}
 */
function location_from_post(string $prefix = ''): array
{
    $lat = coordinate(post($prefix . 'latitude'), -90, 90);
    $lng = coordinate(post($prefix . 'longitude'), -180, 180);
    if ($lat === null || $lng === null) {
        $lat = null;
        $lng = null;
    }
    $place = trim(post($prefix . 'place_id'));
    return [
        'latitude' => $lat,
        'longitude' => $lng,
        'place_id' => $place !== '' ? mb_substr($place, 0, 255) : null,
    ];
}

/**
 * Google Maps link for a pin, or an address search when no pin is available.
 */
function map_link(?float $lat, ?float $lng, string $address = '', bool $directions = false): string
{
    if ($lat !== null && $lng !== null) {
        $point = number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', '');
        return ($directions ? 'https://www.google.com/maps/dir/?api=1&destination=' : 'https://www.google.com/maps/search/?api=1&query=')
            . rawurlencode($point);
    }
    $address = trim($address);
    if ($address === '') {
        return '';
    }
    return ($directions ? 'https://www.google.com/maps/dir/?api=1&destination=' : 'https://www.google.com/maps/search/?api=1&query=')
        . rawurlencode($address);
}

/**
 * Mobile-first Google Maps picker.
 *
 * Options:
 *  - id           unique DOM id
 *  - address_id   id of the address textarea/input to fill
 *  - city_id      optional id of the city input
 *  - pincode_id   optional id of the PIN code input
 *  - landmark_id  optional id of the landmark input
 *  - lat_name     hidden input name for latitude (default latitude)
 *  - lng_name     hidden input name for longitude (default longitude)
 *  - place_name   hidden input name for Google place id (default place_id)
 *  - lat / lng    current values
 *  - place        current Google place id
 *  - label        heading text
 *  - height       map height in pixels (default 280)
 */
function render_location_picker(array $options = []): void
{
    $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($options['id'] ?? 'location-picker')) ?: 'location-picker';
    $addressId = (string) ($options['address_id'] ?? 'address');
    $cityId = (string) ($options['city_id'] ?? '');
    $pincodeId = (string) ($options['pincode_id'] ?? '');
    $landmarkId = (string) ($options['landmark_id'] ?? '');
    $latName = (string) ($options['lat_name'] ?? 'latitude');
    $lngName = (string) ($options['lng_name'] ?? 'longitude');
    $placeName = (string) ($options['place_name'] ?? 'place_id');
    $lat = $options['lat'] ?? null;
    $lng = $options['lng'] ?? null;
    $lat = coordinate($lat !== null ? (string) $lat : null, -90, 90);
    $lng = coordinate($lng !== null ? (string) $lng : null, -180, 180);
    if ($lat === null || $lng === null) {
        $lat = null;
        $lng = null;
    }
    $place = (string) ($options['place'] ?? '');
    $label = (string) ($options['label'] ?? 'Pin the location on the map');
    $height = max(180, (int) ($options['height'] ?? 280));
    $key = google_maps_api_key();
    $hasPin = $lat !== null && $lng !== null;

    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    ?>
    <div class="location-picker" id="<?= e($id) ?>">
        <div class="location-picker-head">
            <strong><?= e($label) ?></strong>
            <span class="subtle">Tap the map, search an address, or use your current location. The address and pin are saved with the order.</span>
        </div>
        <div class="location-picker-toolbar">
            <button type="button" class="btn btn-sm" data-geo>Use my current location</button>
            <?php if ($key !== ''): ?>
                <input type="text" class="location-search" data-search placeholder="Search address, landmark or shop name" autocomplete="off">
            <?php endif; ?>
            <span class="subtle" data-geo-status></span>
        </div>
        <?php if ($key !== ''): ?>
            <div class="location-map" data-map style="height:<?= e((string) $height) ?>px"></div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin:10px 0 0;">
                Google Maps is not configured yet. Add an API key in <strong>Admin → Settings</strong> to show the map.
                You can still type the address manually or use <em>Use my current location</em> to save the pin.
            </div>
        <?php endif; ?>
        <input type="hidden" name="<?= e($latName) ?>" value="<?= $hasPin ? e(number_format((float) $lat, 7, '.', '')) : '' ?>" data-lat>
        <input type="hidden" name="<?= e($lngName) ?>" value="<?= $hasPin ? e(number_format((float) $lng, 7, '.', '')) : '' ?>" data-lng>
        <input type="hidden" name="<?= e($placeName) ?>" value="<?= e($place) ?>" data-place>
        <div class="location-picker-foot subtle" data-location-summary>
            <?= $hasPin ? 'Pin saved: ' . e(number_format((float) $lat, 6, '.', '') . ', ' . number_format((float) $lng, 6, '.', '')) : 'No pin selected yet - type the address or pick a point on the map.' ?>
        </div>
    </div>
    <script>
    (function () {
        var root = document.getElementById(<?= json_encode($id, $jsonFlags) ?>);
        if (!root) { return; }
        var addressEl = document.getElementById(<?= json_encode($addressId, $jsonFlags) ?>);
        var cityEl = <?= $cityId !== '' ? 'document.getElementById(' . json_encode($cityId, $jsonFlags) . ')' : 'null' ?>;
        var pincodeEl = <?= $pincodeId !== '' ? 'document.getElementById(' . json_encode($pincodeId, $jsonFlags) . ')' : 'null' ?>;
        var landmarkEl = <?= $landmarkId !== '' ? 'document.getElementById(' . json_encode($landmarkId, $jsonFlags) . ')' : 'null' ?>;
        var latEl = root.querySelector('[data-lat]');
        var lngEl = root.querySelector('[data-lng]');
        var placeEl = root.querySelector('[data-place]');
        var mapEl = root.querySelector('[data-map]');
        var statusEl = root.querySelector('[data-geo-status]');
        var summaryEl = root.querySelector('[data-location-summary]');
        var searchEl = root.querySelector('[data-search]');
        var geoBtn = root.querySelector('[data-geo]');
        var key = <?= json_encode($key, $jsonFlags) ?>;
        var map = null;
        var marker = null;
        var geocoder = null;
        var autocomplete = null;

        function setStatus(text) {
            if (statusEl) { statusEl.textContent = text || ''; }
        }
        function setSummary() {
            if (!summaryEl) { return; }
            if (latEl.value !== '' && lngEl.value !== '') {
                summaryEl.textContent = 'Pin saved: ' + Number(latEl.value).toFixed(6) + ', ' + Number(lngEl.value).toFixed(6);
            } else {
                summaryEl.textContent = 'No pin selected yet - type the address or pick a point on the map.';
            }
        }
        function component(components, type) {
            if (!components) { return ''; }
            for (var i = 0; i < components.length; i++) {
                if (components[i].types && components[i].types.indexOf(type) !== -1) {
                    return components[i].long_name || '';
                }
            }
            return '';
        }
        function fillAddress(components, formatted, placeId, placeName) {
            if (addressEl && formatted) { addressEl.value = formatted; }
            if (cityEl) {
                var city = component(components, 'locality') || component(components, 'administrative_area_level_2') || component(components, 'administrative_area_level_3');
                if (city) { cityEl.value = city; }
            }
            if (pincodeEl) {
                var pin = component(components, 'postal_code');
                if (pin) { pincodeEl.value = pin; }
            }
            if (landmarkEl && placeName) { landmarkEl.value = placeName; }
            if (placeEl) { placeEl.value = placeId || ''; }
            setSummary();
        }
        function reverseGeocode(latLng) {
            if (!geocoder) { return; }
            geocoder.geocode({location: latLng}, function (results, status) {
                if (status === 'OK' && results && results[0]) {
                    var result = results[0];
                    fillAddress(result.address_components, result.formatted_address, result.place_id, '');
                }
            });
        }
        function placeMarker(latLng, fill) {
            latEl.value = Number(latLng.lat()).toFixed(7);
            lngEl.value = Number(latLng.lng()).toFixed(7);
            setSummary();
            if (!map) { return; }
            if (marker) {
                marker.setPosition(latLng);
            } else {
                marker = new google.maps.Marker({map: map, position: latLng, draggable: true});
                marker.addListener('dragend', function () {
                    placeMarker(marker.getPosition(), true);
                });
            }
            map.panTo(latLng);
            if (fill) { reverseGeocode(latLng); }
        }
        function useCurrentPosition(position) {
            var latLng = {lat: position.coords.latitude, lng: position.coords.longitude};
            if (map) {
                placeMarker(new google.maps.LatLng(latLng.lat, latLng.lng), true);
                setStatus('Current location captured.');
            } else {
                latEl.value = latLng.lat.toFixed(7);
                lngEl.value = latLng.lng.toFixed(7);
                setSummary();
                setStatus('Current location captured. Type the address to complete it.');
            }
        }
        function requestLocation() {
            if (!navigator.geolocation) {
                setStatus('This browser does not support location access. Please pick the point on the map.');
                return;
            }
            setStatus('Getting your current location...');
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    useCurrentPosition(position);
                },
                function (error) {
                    setStatus('Could not get your location: ' + (error.message || 'permission denied') + '. You can still pick the point on the map.');
                },
                {enableHighAccuracy: true, timeout: 15000, maximumAge: 0}
            );
        }
        if (geoBtn) { geoBtn.addEventListener('click', requestLocation); }

        if (searchEl) {
            searchEl.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); }
            });
        }

        function initMap() {
            if (!mapEl) { return; }
            var start = {lat: 20.5937, lng: 78.9629};
            var hasPin = latEl.value !== '' && lngEl.value !== '';
            if (hasPin) {
                start = {lat: parseFloat(latEl.value), lng: parseFloat(lngEl.value)};
            }
            map = new google.maps.Map(mapEl, {
                center: start,
                zoom: hasPin ? 16 : 5,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false
            });
            geocoder = new google.maps.Geocoder();
            if (hasPin) {
                marker = new google.maps.Marker({map: map, position: start, draggable: true});
                marker.addListener('dragend', function () {
                    placeMarker(marker.getPosition(), true);
                });
            }
            map.addListener('click', function (event) {
                placeMarker(event.latLng, true);
            });
            if (searchEl) {
                try {
                    autocomplete = new google.maps.places.Autocomplete(searchEl, {
                        fields: ['geometry', 'formatted_address', 'address_components', 'place_id', 'name']
                    });
                    autocomplete.addListener('place_changed', function () {
                        var place = autocomplete.getPlace();
                        if (!place || !place.geometry || !place.geometry.location) {
                            setStatus('Please choose an address from the suggestions.');
                            return;
                        }
                        placeMarker(place.geometry.location, false);
                        fillAddress(place.address_components, place.formatted_address || place.name || '', place.place_id, place.name || '');
                        setStatus('Address selected.');
                    });
                } catch (err) {
                    setStatus('Address search is unavailable. Tap the map or type the address instead.');
                }
            }
        }

        if (key !== '') {
            if (!window.__lfLoadGoogleMaps) {
                window.__lfLoadGoogleMaps = (function () {
                    var state = {loading: false, ready: false, callbacks: []};
                    return function (apiKey, callback) {
                        if (state.ready) { callback(); return; }
                        state.callbacks.push(callback);
                        if (state.loading) { return; }
                        state.loading = true;
                        window.__lfGoogleMapsReady = function () {
                            state.ready = true;
                            var callbacks = state.callbacks;
                            state.callbacks = [];
                            callbacks.forEach(function (fn) {
                                try { fn(); } catch (err) { if (window.console) { console.error(err); } }
                            });
                        };
                        var script = document.createElement('script');
                        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(apiKey) + '&libraries=places&loading=async&callback=__lfGoogleMapsReady';
                        script.async = true;
                        script.defer = true;
                        script.onerror = function () {
                            setStatus('The map could not be loaded. Check the Google Maps API key and your internet connection.');
                        };
                        document.head.appendChild(script);
                    };
                })();
            }
            window.__lfLoadGoogleMaps(key, initMap);
        }
    })();
    </script>
    <?php
}
