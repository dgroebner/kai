/**
 * UNDER THE HOOD 🏎️ — 3D Panoramic Cockpit & Seamless Sunny Coastal Circuit
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. WEBAUDIO SOUND ENGINE (Synthetisch — ohne externe MP3s!)
    // -------------------------------------------------------------------------
    class HoodAudio {
        constructor() {
            this.ctx = null;
            this.enabled = true;
        }

        init() {
            if (!this.ctx) {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (AudioCtx) this.ctx = new AudioCtx();
            }
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume();
            }
        }

        playTone(freq, type, duration, gainStart = 0.2, gainEnd = 0.001) {
            if (!this.enabled || !this.ctx) return;
            try {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = type;
                osc.frequency.setValueAtTime(freq, this.ctx.currentTime);
                gain.gain.setValueAtTime(gainStart, this.ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(gainEnd, this.ctx.currentTime + duration);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + duration);
            } catch (e) {}
        }

        playRoll() {
            this.init();
            for (let i = 0; i < 4; i++) {
                setTimeout(() => {
                    this.playTone(200 + Math.random() * 90, 'triangle', 0.08, 0.15);
                }, i * 60);
            }
        }

        playEngine() {
            this.init();
            if (!this.enabled || !this.ctx) return;
            try {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(90, this.ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(260, this.ctx.currentTime + 0.5);
                gain.gain.setValueAtTime(0.12, this.ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + 0.55);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + 0.55);
            } catch (e) {}
        }

        playCash() {
            this.init();
            this.playTone(987.77, 'sine', 0.12, 0.2);
            setTimeout(() => this.playTone(1318.51, 'sine', 0.35, 0.25), 100);
        }

        playBuild() {
            this.init();
            this.playTone(400, 'square', 0.08, 0.15);
            setTimeout(() => this.playTone(600, 'triangle', 0.15, 0.2), 90);
        }

        playHorn() {
            this.init();
            this.playTone(330, 'sawtooth', 0.2, 0.18);
            setTimeout(() => this.playTone(330, 'sawtooth', 0.3, 0.18), 240);
        }
    }

    // -------------------------------------------------------------------------
    // 2. HAUPTKLASSE: HOOD GAME (PANORAMA-COCKPIT & NAHTLOSE KÜSTENSTRECKE)
    // -------------------------------------------------------------------------
    class HoodGame {
        constructor() {
            this.audio = new HoodAudio();
            this.roomCode = null;
            this.playerToken = null;
            this.myPlayerId = null;
            this.isHost = false;
            this.state = null;
            this.stateVersion = 0;
            this.pollTimer = null;

            // Three.js Variablen
            this.scene = null;
            this.camera = null;
            this.renderer = null;
            this.container = document.getElementById('hood-canvas-container');
            this.tileMeshes = [];
            this.tilePlotGroups = [];
            this.isDriving = false;

            // Strecken-Kurve & 40 Stationen
            this.trackCurve = null;
            this.trackPoints = [];

            // Cockpit Rig
            this.cockpitRig = null;
            this.steeringWheel = null;
            this.carHoodMat = null;
            this.carHoodMesh = null;
            this.cockpitYaw = -0.7; // Standard: Blick schräg rechts zum Grundstück
            this.cockpitPitch = 0.0;
            this.targetYaw = -0.7;
            this.targetPitch = 0.0;

            // Touch Drag für freies Umsehen
            this.isDragging = false;
            this.prevTouch = { x: 0, y: 0 };

            // UI-Elemente
            this.ui = {
                roomBadge: document.getElementById('room-badge-code'),
                cashBadge: document.getElementById('cash-badge-amount'),
                tickerText: document.getElementById('ticker-text'),
                naviZoneTag: document.getElementById('navi-zone-tag'),
                naviName: document.getElementById('navi-name'),
                naviOwner: document.getElementById('navi-owner'),
                naviPrice: document.getElementById('navi-price'),
                naviRent: document.getElementById('navi-rent'),
                statsPurchasable: document.getElementById('navi-stats-purchasable'),
                eventBox: document.getElementById('navi-event-box'),
                eventIcon: document.getElementById('navi-event-icon'),
                eventTitle: document.getElementById('navi-event-title'),
                eventDesc: document.getElementById('navi-event-desc'),
                btnRoll: document.getElementById('btn-roll'),
                btnGroupBuy: document.getElementById('btn-group-buy'),
                btnBuy: document.getElementById('btn-buy'),
                btnPass: document.getElementById('btn-pass'),
                btnEndTurn: document.getElementById('btn-end-turn'),
                btnBail: document.getElementById('btn-bail'),
                btnLookPlot: document.getElementById('btn-look-plot'),
                btnLookRoad: document.getElementById('btn-look-road'),
                lobbyModal: document.getElementById('modal-lobby'),
                sheetBackdrop: document.getElementById('sheet-backdrop'),
                sheetTitle: document.getElementById('sheet-title'),
                sheetPrice: document.getElementById('sheet-price'),
                sheetOwner: document.getElementById('sheet-owner'),
                sheetBtnBuild: document.getElementById('sheet-btn-build'),
                sheetBtnMortgage: document.getElementById('sheet-btn-mortgage'),
                winnerModal: document.getElementById('modal-winner'),
                winnerText: document.getElementById('winner-text'),
            };

            this.selectedTileIndex = null;
        }

        init() {
            const urlParams = new URLSearchParams(window.location.search);
            this.roomCode = (urlParams.get('room') || '').toUpperCase();
            this.playerToken = localStorage.getItem('quarxl_hood_token_' + this.roomCode) || null;

            this.setupThree();
            this.initSplineTrack();
            this.buildSeamlessRoadRibbon();
            this.buildNaturalEnvironment();
            this.buildPanoramacockpit();
            this.bindEvents();

            if (this.roomCode) {
                this.loadRoomState();
                this.startPolling();
            } else {
                this.showLobbyModal(true);
            }
        }

        // ---------------------------------------------------------------------
        // 3. THREE.JS SZENE: STRAHLENDER, SONNIGER TAG MIT WOLKEN & HÜGELN
        // ---------------------------------------------------------------------
        setupThree() {
            const w = this.container.clientWidth;
            const h = this.container.clientHeight;

            this.scene = new THREE.Scene();
            // Frischer, klarer Sommerhimmel
            this.scene.background = new THREE.Color(0x7dd3fc);
            this.scene.fog = new THREE.FogExp2(0xbfe0f7, 0.0015);

            this.camera = new THREE.PerspectiveCamera(65, w / h, 0.1, 1600);

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
            this.renderer.setSize(w, h);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            this.renderer.shadowMap.enabled = true;
            this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
            this.renderer.toneMappingExposure = 1.15;
            this.container.appendChild(this.renderer.domElement);

            // Warme Sommersonne
            const sun = new THREE.DirectionalLight(0xfffbeb, 1.85);
            sun.position.set(180, 280, 140);
            sun.castShadow = true;
            sun.shadow.mapSize.width = 2048;
            sun.shadow.mapSize.height = 2048;
            sun.shadow.camera.near = 10;
            sun.shadow.camera.far = 900;
            const d = 260;
            sun.shadow.camera.left = -d;
            sun.shadow.camera.right = d;
            sun.shadow.camera.top = d;
            sun.shadow.camera.bottom = -d;
            sun.shadow.bias = -0.0003;
            this.scene.add(sun);

            // Blauer Himmels- & grüner Wiesen-Bounce
            const hemi = new THREE.HemisphereLight(0x38bdf8, 0x22c55e, 0.75);
            this.scene.add(hemi);

            const ambient = new THREE.AmbientLight(0xe0f2fe, 0.7);
            this.scene.add(ambient);

            // Ozean rund um die Küste
            const oceanGeo = new THREE.PlaneGeometry(2800, 2800);
            const oceanMat = new THREE.MeshStandardMaterial({
                color: 0x0284c7,
                roughness: 0.15,
                metalness: 0.35,
            });
            const ocean = new THREE.Mesh(oceanGeo, oceanMat);
            ocean.rotation.x = -Math.PI / 2;
            ocean.position.y = -2;
            this.scene.add(ocean);

            // Sanfte grüne Hügel-Landschaft
            const islandGeo = new THREE.PlaneGeometry(900, 900, 32, 32);
            // Sanfte Wellenform im Terrain für natürliche Unebenheiten
            const posAttr = islandGeo.attributes.position;
            for (let i = 0; i < posAttr.count; i++) {
                const vx = posAttr.getX(i);
                const vy = posAttr.getY(i);
                const dist = Math.sqrt(vx * vx + vy * vy);
                // Sanfte Erhöhungen im Insel-Inneren
                if (dist < 350) {
                    posAttr.setZ(i, Math.sin(vx * 0.015) * Math.cos(vy * 0.015) * 6 - 0.2);
                }
            }
            islandGeo.computeVertexNormals();

            const islandMat = new THREE.MeshStandardMaterial({
                color: 0x16a34a,
                roughness: 0.85,
            });
            const island = new THREE.Mesh(islandGeo, islandMat);
            island.rotation.x = -Math.PI / 2;
            island.position.y = -0.1;
            island.receiveShadow = true;
            this.scene.add(island);

            // Render-Schleife
            const animate = () => {
                requestAnimationFrame(animate);

                this.cockpitYaw = THREE.MathUtils.lerp(this.cockpitYaw, this.targetYaw, 0.08);
                this.cockpitPitch = THREE.MathUtils.lerp(this.cockpitPitch, this.targetPitch, 0.08);

                if (this.camera) {
                    this.camera.rotation.set(this.cockpitPitch, this.cockpitYaw, 0, 'YXZ');
                }

                if (this.steeringWheel && this.isDriving) {
                    this.steeringWheel.rotation.z = Math.sin(Date.now() * 0.005) * 0.25;
                }

                this.renderer.render(this.scene, this.camera);
            };
            animate();

            window.addEventListener('resize', () => {
                const nw = this.container.clientWidth;
                const nh = this.container.clientHeight;
                this.camera.aspect = nw / nh;
                this.camera.updateProjectionMatrix();
                this.renderer.setSize(nw, nh);
            });
        }

        // ---------------------------------------------------------------------
        // 4. NATÜRLICHE KÜSTEN-STRECKE (SPLINE) & NAHTLOSER STRASSEN-RIBBON
        // ---------------------------------------------------------------------
        initSplineTrack() {
            // Kontrollpunkte für einen sanft geschwungenen, harmonischen Rundkurs
            const controlPoints = [
                new THREE.Vector3(0, 0, 160),      // 0: Start & Ziel
                new THREE.Vector3(75, 0, 155),     // Docks
                new THREE.Vector3(145, 0, 120),    // North Beach
                new THREE.Vector3(185, 0, 50),     // Vorstadt
                new THREE.Vector3(195, 0, -40),    // Künstlerviertel
                new THREE.Vector3(160, 0, -125),   // Palm Bay
                new THREE.Vector3(90, 0, -170),    // Flaniermeile
                new THREE.Vector3(0, 0, -185),     // Downtown Skyline (Nordseite)
                new THREE.Vector3(-90, 0, -175),   // Marina
                new THREE.Vector3(-155, 0, -125),  // Sunset Bay
                new THREE.Vector3(-190, 0, -45),   // Panorama-Höhen
                new THREE.Vector3(-180, 0, 50),    // Ocean Pier
                new THREE.Vector3(-140, 0, 120),   // Millionaires’ Hill
                new THREE.Vector3(-70, 0, 155),    // Anfahrt Start
            ];

            this.trackCurve = new THREE.CatmullRomCurve3(controlPoints, true, 'centripetal', 0.5);

            // 40 gleichmäßig verteilte Stationen
            this.trackPoints = [];
            for (let i = 0; i < 40; i++) {
                const u = i / 40.0;
                const pos = this.trackCurve.getPointAt(u);
                const tangent = this.trackCurve.getTangentAt(u).normalize();

                // Normalenvektor nach rechts: N = (-tangent.z, 0, tangent.x)
                const normal = new THREE.Vector3(-tangent.z, 0, tangent.x).normalize();
                const angle = Math.atan2(-tangent.x, -tangent.z);

                this.trackPoints.push({
                    index: i,
                    pos: pos,
                    tangent: tangent,
                    normal: normal,
                    angle: angle,
                });
            }
        }

        getTileTypeByIndex(i) {
            if (i === 0) return 'start';
            if (i === 5 || i === 15 || i === 25 || i === 35) return 'beach';
            if (i === 12 || i === 28) return 'club';
            if (i === 10) return 'jail';
            if (i === 20) return 'parking';
            if (i === 30) return 'go_to_jail';
            if (i === 4 || i === 38) return 'tax';
            if ([2, 7, 17, 22, 33, 36].includes(i)) return 'card';
            return 'street';
        }

        // Erzeugt ein 100% nahtloses, mathematisch weiches Straßen- & Gehwegband mit Markierungen
        buildSeamlessRoadRibbon() {
            const SAMPLES = 240; // Hohe Auflösung für perfekte Rundungen ohne Kanten
            const roadWidth = 13;
            const walkWidth = 6.5;

            const roadPositions = [];
            const walkPositions = [];
            const curbPositions = [];
            const yellowLinePositions = [];
            const whiteLinePositions = [];

            const roadIndices = [];
            const walkIndices = [];
            const curbIndices = [];
            const yellowLineIndices = [];
            const whiteLineIndices = [];

            let yCount = 0;
            let wCount = 0;

            for (let i = 0; i <= SAMPLES; i++) {
                const u = (i % SAMPLES) / SAMPLES;
                const p = this.trackCurve.getPointAt(u);
                const t = this.trackCurve.getTangentAt(u).normalize();
                const n = new THREE.Vector3(-t.z, 0, t.x).normalize();

                // 1. Straßenband (Höhe y = 0.2)
                const rL = p.clone().add(n.clone().multiplyScalar(-roadWidth / 2));
                const rR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2));
                roadPositions.push(rL.x, 0.2, rL.z);
                roadPositions.push(rR.x, 0.2, rR.z);

                // 2. Bordsteinkante aus Granit (Höhe y = 0.28, Breite 0.6)
                const cL = rR.clone();
                const cR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 + 0.6));
                curbPositions.push(cL.x, 0.28, cL.z);
                curbPositions.push(cR.x, 0.28, cR.z);

                // 3. Gehwegband rechts (Höhe y = 0.35 aus Naturstein)
                const wL = cR.clone();
                const wR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 + walkWidth));
                walkPositions.push(wL.x, 0.35, wL.z);
                walkPositions.push(wR.x, 0.35, wR.z);

                // 4. Fahrbahnmarkierungen: Durchgezogene weiße Außenlinie rechts & gestrichelte gelbe Mittellinie
                if (i < SAMPLES) {
                    const base = i * 2;
                    roadIndices.push(base, base + 1, base + 2);
                    roadIndices.push(base + 1, base + 3, base + 2);

                    curbIndices.push(base, base + 1, base + 2);
                    curbIndices.push(base + 1, base + 3, base + 2);

                    walkIndices.push(base, base + 1, base + 2);
                    walkIndices.push(base + 1, base + 3, base + 2);

                    // Weiße Begrenzungslinie (Höhe y = 0.22)
                    const wl1 = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 - 0.5));
                    const wl2 = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 - 0.25));
                    const nextP = this.trackCurve.getPointAt(((i + 1) % SAMPLES) / SAMPLES);
                    const nextT = this.trackCurve.getTangentAt(((i + 1) % SAMPLES) / SAMPLES).normalize();
                    const nextN = new THREE.Vector3(-nextT.z, 0, nextT.x).normalize();
                    const wl3 = nextP.clone().add(nextN.clone().multiplyScalar(roadWidth / 2 - 0.5));
                    const wl4 = nextP.clone().add(nextN.clone().multiplyScalar(roadWidth / 2 - 0.25));

                    const wIdx = wCount * 4;
                    whiteLinePositions.push(wl1.x, 0.22, wl1.z, wl2.x, 0.22, wl2.z, wl3.x, 0.22, wl3.z, wl4.x, 0.22, wl4.z);
                    whiteLineIndices.push(wIdx, wIdx + 1, wIdx + 2, wIdx + 1, wIdx + 3, wIdx + 2);
                    wCount++;

                    // Gelbe gestrichelte Mittellinie (2 Abschnitte gelb, 1 Abschnitt frei)
                    if (i % 3 < 2) {
                        const yl1 = p.clone().add(n.clone().multiplyScalar(-0.2));
                        const yl2 = p.clone().add(n.clone().multiplyScalar(0.2));
                        const yl3 = nextP.clone().add(nextN.clone().multiplyScalar(-0.2));
                        const yl4 = nextP.clone().add(nextN.clone().multiplyScalar(0.2));

                        const yIdx = yCount * 4;
                        yellowLinePositions.push(yl1.x, 0.22, yl1.z, yl2.x, 0.22, yl2.z, yl3.x, 0.22, yl3.z, yl4.x, 0.22, yl4.z);
                        yellowLineIndices.push(yIdx, yIdx + 1, yIdx + 2, yIdx + 1, yIdx + 3, yIdx + 2);
                        yCount++;
                    }
                }
            }

            // Nahtlose Asphalt-Straße
            const roadGeo = new THREE.BufferGeometry();
            roadGeo.setAttribute('position', new THREE.Float32BufferAttribute(roadPositions, 3));
            roadGeo.setIndex(roadIndices);
            roadGeo.computeVertexNormals();

            const roadMat = new THREE.MeshStandardMaterial({
                color: 0x22262e,
                roughness: 0.88,
            });
            const roadMesh = new THREE.Mesh(roadGeo, roadMat);
            roadMesh.receiveShadow = true;
            this.scene.add(roadMesh);

            // Bordstein aus Granit
            const curbGeo = new THREE.BufferGeometry();
            curbGeo.setAttribute('position', new THREE.Float32BufferAttribute(curbPositions, 3));
            curbGeo.setIndex(curbIndices);
            curbGeo.computeVertexNormals();
            const curbMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.8 });
            const curbMesh = new THREE.Mesh(curbGeo, curbMat);
            this.scene.add(curbMesh);

            // Nahtloser Gehweg aus hellem Naturstein
            const walkGeo = new THREE.BufferGeometry();
            walkGeo.setAttribute('position', new THREE.Float32BufferAttribute(walkPositions, 3));
            walkGeo.setIndex(walkIndices);
            walkGeo.computeVertexNormals();

            const walkMat = new THREE.MeshStandardMaterial({
                color: 0xe2e8f0,
                roughness: 0.75,
            });
            const walkMesh = new THREE.Mesh(walkGeo, walkMat);
            walkMesh.receiveShadow = true;
            this.scene.add(walkMesh);

            // Weiße Randlinie
            const whiteLineGeo = new THREE.BufferGeometry();
            whiteLineGeo.setAttribute('position', new THREE.Float32BufferAttribute(whiteLinePositions, 3));
            whiteLineGeo.setIndex(whiteLineIndices);
            const whiteLineMat = new THREE.MeshBasicMaterial({ color: 0xf8fafc });
            this.scene.add(new THREE.Mesh(whiteLineGeo, whiteLineMat));

            // Gelbe Mittellinie
            const yellowLineGeo = new THREE.BufferGeometry();
            yellowLineGeo.setAttribute('position', new THREE.Float32BufferAttribute(yellowLinePositions, 3));
            yellowLineGeo.setIndex(yellowLineIndices);
            const yellowLineMat = new THREE.MeshBasicMaterial({ color: 0xfacc15 });
            this.scene.add(new THREE.Mesh(yellowLineGeo, yellowLineMat));

            // Die 40 Grundstücke entlang des Gehwegs platzieren
            for (let i = 0; i < 40; i++) {
                const tp = this.trackPoints[i];
                const plotGroup = new THREE.Group();

                // Grundstück sicher 22m außerhalb des Straßenmittelpunkts
                const plotPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(22));
                plotGroup.position.copy(plotPos);
                plotGroup.rotation.y = tp.angle;

                this.tilePlotGroups[i] = plotGroup;
                this.renderPlotState({ index: i, type: this.getTileTypeByIndex(i), level: 0 }, null, false);
                this.scene.add(plotGroup);

                // Elegante geschwungene Straßenlaterne auf der linken Straßenseite (damit Grundstücke rechts 100% frei sichtbar sind!)
                if (i % 2 === 0) {
                    const lampPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(-7.5));
                    this.addCurvedStreetLamp(lampPos, tp.angle + Math.PI);
                }
            }
        }

        addCurvedStreetLamp(pos, rotY) {
            const lampGroup = new THREE.Group();
            lampGroup.position.copy(pos);
            lampGroup.rotation.y = rotY;

            const poleGeo = new THREE.CylinderGeometry(0.1, 0.14, 6.2, 12);
            const poleMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.6 });
            const pole = new THREE.Mesh(poleGeo, poleMat);
            pole.position.y = 3.1;
            pole.castShadow = true;
            lampGroup.add(pole);

            // Bogen nach vorn
            const armGeo = new THREE.BoxGeometry(0.1, 0.1, 1.2);
            const arm = new THREE.Mesh(armGeo, poleMat);
            arm.position.set(-0.5, 6.1, 0);
            lampGroup.add(arm);

            // Laternenkopf mit warmem Licht
            const headGeo = new THREE.SphereGeometry(0.35, 12, 12);
            const headMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
            const head = new THREE.Mesh(headGeo, headMat);
            head.position.set(-1.0, 5.9, 0);
            lampGroup.add(head);

            this.scene.add(lampGroup);
        }

        // ---------------------------------------------------------------------
        // 5. NATÜRLICHE UMGEBUNG: 3D-WOLKEN, KÜSTEN-HÜGEL, BÄUME & PALMEN
        // ---------------------------------------------------------------------
        buildNaturalEnvironment() {
            // 1. Sanfte Panoramahügel im Hintergrund für organische Landschaft
            const hillMat = new THREE.MeshStandardMaterial({
                color: 0x15803d,
                roughness: 0.9,
            });

            const hillConfigs = [
                { x: 0, z: -320, r: 130, h: 45 },
                { x: 260, z: -180, r: 110, h: 38 },
                { x: 300, z: 120, r: 120, h: 42 },
                { x: 0, z: 320, r: 140, h: 48 },
                { x: -280, z: 140, r: 115, h: 40 },
                { x: -290, z: -190, r: 125, h: 44 },
                // Insel-Innere sanfte Hügel
                { x: 30, z: -20, r: 85, h: 22 },
                { x: -40, z: 30, r: 75, h: 18 },
            ];

            hillConfigs.forEach(hc => {
                const hillGeo = new THREE.ConeGeometry(hc.r, hc.h, 24);
                const hill = new THREE.Mesh(hillGeo, hillMat);
                hill.position.set(hc.x, hc.h / 2 - 1, hc.z);
                hill.receiveShadow = true;
                this.scene.add(hill);
            });

            // 2. Weißer Sandstrand-Ring um die Küste
            const beachGeo = new THREE.RingGeometry(220, 260, 48);
            const beachMat = new THREE.MeshStandardMaterial({ color: 0xfef08a, roughness: 0.95 });
            const beachRing = new THREE.Mesh(beachGeo, beachMat);
            beachRing.rotation.x = -Math.PI / 2;
            beachRing.position.y = -0.05;
            this.scene.add(beachRing);

            // 3. Flauschige 3D-Sommerwolken am Himmel
            const cloudMat = new THREE.MeshStandardMaterial({
                color: 0xffffff,
                roughness: 0.9,
                flatShading: true,
            });

            for (let i = 0; i < 20; i++) {
                const cloudGroup = new THREE.Group();
                const puffCount = 4 + Math.floor(Math.random() * 4);
                for (let p = 0; p < puffCount; p++) {
                    const puffGeo = new THREE.SphereGeometry(7 + Math.random() * 5, 12, 10);
                    const puff = new THREE.Mesh(puffGeo, cloudMat);
                    puff.position.set(
                        (p - puffCount / 2) * 8 + (Math.random() - 0.5) * 4,
                        Math.random() * 3,
                        (Math.random() - 0.5) * 6
                    );
                    cloudGroup.add(puff);
                }

                const angle = Math.random() * Math.PI * 2;
                const dist = 180 + Math.random() * 260;
                cloudGroup.position.set(
                    Math.cos(angle) * dist,
                    80 + Math.random() * 35,
                    Math.sin(angle) * dist
                );
                this.scene.add(cloudGroup);
            }

            // 4. Schattige Laubbäume & Palmen entlang der Küste
            for (let i = 0; i < 70; i++) {
                const angle = (i / 70) * Math.PI * 2;
                const dist = 95 + Math.sin(i * 3) * 65 + (Math.random() - 0.5) * 35;
                const tx = Math.cos(angle) * dist;
                const tz = Math.sin(angle) * dist;

                this.addStylizedTree(tx, tz, i % 3 === 0);
            }
        }

        // Erzeugt natürliche Bäume (geschwungene Palmen oder weiche Laubbäume)
        addStylizedTree(x, z, isPalm = false) {
            const tree = new THREE.Group();
            tree.position.set(x, 0, z);

            if (isPalm) {
                // Tropische Kokospalme mit geschwungenem Stamm
                const trunkGroup = new THREE.Group();
                const trunkMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.9 });
                const segHeights = [2.2, 2.0, 1.8];
                let curY = 0;
                let curTilt = 0;
                const tiltAngle = (Math.random() - 0.5) * 0.15;

                segHeights.forEach((h, idx) => {
                    const segGeo = new THREE.CylinderGeometry(0.22 - idx * 0.03, 0.28 - idx * 0.03, h, 10);
                    const seg = new THREE.Mesh(segGeo, trunkMat);
                    seg.position.set(curTilt, curY + h / 2, 0);
                    seg.rotation.z = tiltAngle * idx;
                    seg.castShadow = true;
                    trunkGroup.add(seg);
                    curY += h;
                    curTilt += Math.sin(tiltAngle * idx) * h;
                });
                tree.add(trunkGroup);

                // 8 geschwungene Palmwedel
                const frondMat = new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.8 });
                for (let f = 0; f < 8; f++) {
                    const frondGroup = new THREE.Group();
                    frondGroup.position.set(curTilt, curY, 0);
                    frondGroup.rotation.y = (f / 8) * Math.PI * 2;
                    frondGroup.rotation.z = 0.55; // Nach unten geschwungen

                    const leafGeo = new THREE.BoxGeometry(0.5, 0.06, 3.2);
                    const leaf = new THREE.Mesh(leafGeo, frondMat);
                    leaf.position.set(0, -0.2, 1.6);
                    leaf.castShadow = true;
                    frondGroup.add(leaf);
                    tree.add(frondGroup);
                }

                // 3 Coconuts
                const coconutMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.9 });
                for (let c = 0; c < 3; c++) {
                    const coco = new THREE.Mesh(new THREE.SphereGeometry(0.22, 8, 8), coconutMat);
                    coco.position.set(curTilt + (c - 1) * 0.2, curY - 0.2, (c % 2) * 0.2);
                    tree.add(coco);
                }
            } else {
                // Schöner Laubbaum mit weicher, natürlicher Kugelkrone (keine harten Dodekaeder!)
                const trunkGeo = new THREE.CylinderGeometry(0.32, 0.52, 3.8, 10);
                const trunkMat = new THREE.MeshStandardMaterial({ color: 0x5c3817, roughness: 0.9 });
                const trunk = new THREE.Mesh(trunkGeo, trunkMat);
                trunk.position.y = 1.9;
                trunk.castShadow = true;
                tree.add(trunk);

                // Natürliche Krone aus 4 überlappenden weichen Kugeln mit Farbabstufungen
                const leafMat1 = new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.85 });
                const leafMat2 = new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.85 });
                const leafMat3 = new THREE.MeshStandardMaterial({ color: 0x22c55e, roughness: 0.85 });

                const c1 = new THREE.Mesh(new THREE.SphereGeometry(2.6, 14, 12), leafMat1);
                c1.position.set(0, 4.8, 0);
                c1.castShadow = true;
                tree.add(c1);

                const c2 = new THREE.Mesh(new THREE.SphereGeometry(2.1, 14, 12), leafMat2);
                c2.position.set(1.1, 5.2, -0.5);
                c2.castShadow = true;
                tree.add(c2);

                const c3 = new THREE.Mesh(new THREE.SphereGeometry(1.9, 14, 12), leafMat3);
                c3.position.set(-0.9, 5.1, 0.6);
                c3.castShadow = true;
                tree.add(c3);

                const c4 = new THREE.Mesh(new THREE.SphereGeometry(1.8, 14, 12), leafMat2);
                c4.position.set(0.2, 6.2, 0.2);
                c4.castShadow = true;
                tree.add(c4);
            }

            this.scene.add(tree);
        }

        // ---------------------------------------------------------------------
        // 6. DAS PANORAMA-COCKPIT (AUTHENTISCHER INNENRAUM, FREIE SICHT NACH RECHTS)
        // ---------------------------------------------------------------------
        buildPanoramacockpit() {
            this.cockpitRig = new THREE.Group();

            // 1. Aerodynamische Motorhaube vorn (in Fahrtrichtung -Z!)
            const hoodGeo = new THREE.BoxGeometry(3.2, 0.45, 3.4);
            this.carHoodMat = new THREE.MeshStandardMaterial({
                color: 0xef4444,
                roughness: 0.22,
                metalness: 0.7,
            });
            this.carHoodMesh = new THREE.Mesh(hoodGeo, this.carHoodMat);
            this.carHoodMesh.position.set(0, 0.65, -2.4);
            this.carHoodMesh.castShadow = true;
            this.cockpitRig.add(this.carHoodMesh);

            // 2. Geschwungenes Armaturenbrett (dezentes schwarzes Nappaleder)
            const dashGeo = new THREE.BoxGeometry(3.5, 0.5, 1.3);
            const dashMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.9 });
            const dash = new THREE.Mesh(dashGeo, dashMat);
            dash.position.set(0, 0.75, -0.8);
            this.cockpitRig.add(dash);

            // 3. Digitales Tacho-Kombiinstrument vor dem Fahrer (strahlt dezent in Cyan)
            const clusterGeo = new THREE.BoxGeometry(0.85, 0.3, 0.05);
            const clusterMat = new THREE.MeshBasicMaterial({ color: 0x0284c7 });
            const cluster = new THREE.Mesh(clusterGeo, clusterMat);
            cluster.position.set(-0.7, 1.05, -0.65);
            cluster.rotation.x = -0.25;
            this.cockpitRig.add(cluster);

            // Tacho-Hutze über dem Display
            const cowlGeo = new THREE.BoxGeometry(0.95, 0.15, 0.4);
            const cowl = new THREE.Mesh(cowlGeo, dashMat);
            cowl.position.set(-0.7, 1.2, -0.75);
            this.cockpitRig.add(cowl);

            // 4. Sportlenkrad (Links auf Fahrerseite)
            const wheelGroup = new THREE.Group();
            wheelGroup.position.set(-0.7, 0.88, -0.4);
            wheelGroup.rotation.x = 0.35;

            const ringGeo = new THREE.TorusGeometry(0.38, 0.04, 10, 24);
            const wheelMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.6 });
            const ring = new THREE.Mesh(ringGeo, wheelMat);
            wheelGroup.add(ring);

            const hubGeo = new THREE.CylinderGeometry(0.1, 0.1, 0.05, 12);
            hubGeo.rotateX(Math.PI / 2);
            const hub = new THREE.Mesh(hubGeo, wheelMat);
            wheelGroup.add(hub);

            // 3 Lenkradspeichen
            for (let s = 0; s < 3; s++) {
                const spokeGeo = new THREE.BoxGeometry(0.04, 0.28, 0.02);
                const spoke = new THREE.Mesh(spokeGeo, new THREE.MeshStandardMaterial({ color: 0x71717a }));
                spoke.rotation.z = (s * Math.PI * 2) / 3;
                spoke.position.y = -0.14 * Math.cos(spoke.rotation.z);
                spoke.position.x = 0.14 * Math.sin(spoke.rotation.z);
                wheelGroup.add(spoke);
            }

            this.cockpitRig.add(wheelGroup);
            this.steeringWheel = wheelGroup;

            // 5. Beifahrersitz rechts (dezent und tief platziert, blockiert niemals den Blick auf die Grundstücke!)
            const seatGroup = new THREE.Group();
            seatGroup.position.set(0.95, 0.35, 0.5);

            const seatBaseGeo = new THREE.BoxGeometry(0.65, 0.25, 0.65);
            const seatMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.8 });
            const seatBase = new THREE.Mesh(seatBaseGeo, seatMat);
            seatGroup.add(seatBase);

            const seatBackGeo = new THREE.BoxGeometry(0.55, 0.45, 0.15);
            const seatBack = new THREE.Mesh(seatBackGeo, seatMat);
            seatBack.position.set(0, 0.3, 0.15);
            seatBack.rotation.x = -0.15;
            seatGroup.add(seatBack);

            const headrestGeo = new THREE.BoxGeometry(0.28, 0.16, 0.1);
            const headrest = new THREE.Mesh(headrestGeo, seatMat);
            headrest.position.set(0, 0.62, 0.2);
            seatGroup.add(headrest);

            this.cockpitRig.add(seatGroup);

            // 6. Rückspiegel oben in der Mitte
            const mirrorGroup = new THREE.Group();
            mirrorGroup.position.set(0, 1.85, -0.6);

            const mirrorCaseGeo = new THREE.BoxGeometry(0.7, 0.22, 0.06);
            const mirrorCase = new THREE.Mesh(mirrorCaseGeo, dashMat);
            mirrorGroup.add(mirrorCase);

            const mirrorGlassGeo = new THREE.BoxGeometry(0.66, 0.18, 0.02);
            const mirrorGlassMat = new THREE.MeshBasicMaterial({ color: 0x93c5fd });
            const mirrorGlass = new THREE.Mesh(mirrorGlassGeo, mirrorGlassMat);
            mirrorGlass.position.set(0, 0, 0.03);
            mirrorGroup.add(mirrorGlass);

            this.cockpitRig.add(mirrorGroup);

            // 7. Fahrer-Kamera: Sitzt am Steuer und blickt ungehindert nach vorn und rechts!
            // Absolut keine störenden A-Säulen im Sichtfeld!
            this.camera.position.set(-0.7, 1.35, 0.1);
            this.cockpitRig.add(this.camera);

            this.scene.add(this.cockpitRig);
        }

        // ---------------------------------------------------------------------
        // 7. TRANSFORMATION DER GRUNDSTÜCKE: STRÄNDE & BARS ALS BAURUINEN / LUXUS
        // ---------------------------------------------------------------------
        renderPlotState(tileData, ownerColor = null, isMortgaged = false) {
            const tileIndex = typeof tileData === 'object' ? tileData.index : tileData;
            const group = this.tilePlotGroups[tileIndex];
            if (!group) return;

            while (group.children.length > 0) {
                group.remove(group.children[0]);
            }

            const tileType = (typeof tileData === 'object' && tileData.type)
                ? tileData.type
                : this.getTileTypeByIndex(tileIndex);

            const level = (typeof tileData === 'object' && tileData.level !== undefined)
                ? tileData.level
                : 0;

            const tileName = (typeof tileData === 'object' && tileData.name)
                ? tileData.name
                : `Feld ${tileIndex}`;

            if (tileType === 'club') {
                this.renderClubPlot(group, ownerColor, isMortgaged, tileName);
            } else if (tileType === 'beach') {
                this.renderBeachPlot(group, ownerColor, isMortgaged, tileName);
            } else if (tileType === 'street') {
                this.renderStreetPlot(group, level, ownerColor, isMortgaged, tileName);
            } else {
                this.renderSpecialPlot(group, tileType, tileName, tileIndex);
            }
        }

        // ---------------------------------------------------------------------
        // TEXTUR-GENERATOREN (FOTOREALISTISCHE NEON-SCHILDER, FENSTER & ZIEGEL)
        // ---------------------------------------------------------------------
        getNeonFasciaTexture(title = 'The Neon Lounge', isOwned = false, colorHex = '#00f5ff') {
            const key = `fascia_${title}_${isOwned ? colorHex : 'ruin'}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');

            if (isOwned) {
                // --- AKTIV (Wie im Referenzbild: Strahlendes Neon, Cyan & Magenta) ---
                ctx.fillStyle = '#090c14';
                ctx.fillRect(0, 0, 1024, 256);

                // Subtile horizontale Bürsten-Struktur
                ctx.fillStyle = 'rgba(255, 255, 255, 0.025)';
                for (let y = 0; y < 256; y += 4) {
                    ctx.fillRect(0, y, 1024, 1.5);
                }

                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 8;
                ctx.strokeRect(4, 4, 1016, 248);

                ctx.save();
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';

                const drawNeon = (x1, y1, x2, y2, col, w) => {
                    ctx.shadowColor = col;
                    ctx.shadowBlur = 24;
                    ctx.strokeStyle = col;
                    ctx.lineWidth = w + 4;
                    ctx.beginPath();
                    ctx.moveTo(x1, y1);
                    ctx.lineTo(x2, y2);
                    ctx.stroke();

                    ctx.shadowBlur = 6;
                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = w * 0.45;
                    ctx.stroke();
                };

                // Geometrische Neon-Linien Links (Cyan & Magenta wie im Referenzbild!)
                drawNeon(45, 65, 205, 65, '#00f5ff', 5);
                drawNeon(75, 45, 185, 205, '#ec4899', 5);
                drawNeon(135, 205, 225, 125, '#00f5ff', 5);
                drawNeon(45, 185, 145, 185, '#00f5ff', 5);

                // Geometrische Neon-Linien Rechts ("X" & Zickzack wie im Bild!)
                drawNeon(800, 65, 930, 205, '#ec4899', 5);
                drawNeon(930, 65, 800, 205, '#00f5ff', 5);
                drawNeon(860, 50, 960, 125, '#00f5ff', 5);
                drawNeon(830, 205, 970, 205, '#ec4899', 5);

                // Schriftzug "The Neon Lounge" in eleganter Leuchtschrift
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = 'italic 700 86px "Brush Script MT", "Segoe Script", "Dancing Script", cursive, sans-serif';

                ctx.shadowColor = colorHex;
                ctx.shadowBlur = 35;
                ctx.fillStyle = colorHex;
                ctx.fillText(title, 512, 128);

                ctx.shadowBlur = 14;
                ctx.fillText(title, 512, 128);

                ctx.shadowBlur = 4;
                ctx.fillStyle = '#f8fafc';
                ctx.fillText(title, 512, 128);
                ctx.restore();

            } else {
                // --- BAURUINE (Wie im Ruinen-Referenzbild: Ausgeschaltetes Metall, Rost, Efeu, Graffiti) ---
                ctx.fillStyle = '#30353f';
                ctx.fillRect(0, 0, 1024, 256);

                // Dunkle Regen- und Schmutzschlieren
                for (let x = 0; x < 1024; x += 32) {
                    const h = 80 + (Math.sin(x * 0.1) + 1) * 70;
                    const grad = ctx.createLinearGradient(x, 0, x, h);
                    grad.addColorStop(0, 'rgba(15, 20, 25, 0.7)');
                    grad.addColorStop(1, 'rgba(15, 20, 25, 0.0)');
                    ctx.fillStyle = grad;
                    ctx.fillRect(x, 0, 28, h);
                }

                // Ausgeschaltete, rostige Metallbuchstaben
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = 'italic 700 86px "Brush Script MT", "Segoe Script", "Dancing Script", cursive, sans-serif';

                ctx.shadowColor = 'rgba(0, 0, 0, 0.85)';
                ctx.shadowBlur = 10;
                ctx.fillStyle = '#1c1917';
                ctx.fillText(title, 512, 128);

                ctx.strokeStyle = '#44403c';
                ctx.lineWidth = 3;
                ctx.strokeText(title, 512, 128);

                // Lose Kabel & Halterungen der zerstörten Neon-Röhren
                ctx.strokeStyle = '#18181b';
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.moveTo(80, 70);
                ctx.bezierCurveTo(90, 140, 60, 160, 70, 210);
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(850, 70);
                ctx.bezierCurveTo(860, 130, 840, 170, 850, 220);
                ctx.stroke();

                // Grüner Efeu & Moos, der vom Dach über den Schriftzug wuchert
                ctx.fillStyle = '#1e3a1e';
                for (let ix = 0; ix < 1024; ix += 18) {
                    const drop = 20 + Math.sin(ix * 0.2) * 25 + (Math.cos(ix * 0.05) + 1) * 20;
                    ctx.beginPath();
                    ctx.arc(ix, drop * 0.6, 12, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.beginPath();
                    ctx.arc(ix + 6, drop, 8, 0, Math.PI * 2);
                    ctx.fill();
                }

                // Graffiti-Tags
                ctx.font = 'bold 36px sans-serif';
                ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
                ctx.fillText('NO FUTURE', 220, 210);
                ctx.fillStyle = 'rgba(236, 72, 153, 0.4)';
                ctx.fillText('VOID', 740, 210);
                ctx.restore();
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getBarWindowTexture(isOwned = false, colorHex = '#00f5ff') {
            const key = `window_${isOwned ? colorHex : 'ruin'}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            if (isOwned) {
                // --- AKTIV: Glühende Bar, Regale voller Flaschen, Cocktails ---
                const grad = ctx.createLinearGradient(0, 0, 0, 512);
                grad.addColorStop(0, '#040711');
                grad.addColorStop(0.5, '#0a1020');
                grad.addColorStop(1, '#020306');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, 512, 512);

                ctx.fillStyle = 'rgba(0, 245, 255, 0.28)';
                ctx.fillRect(40, 160, 432, 26);
                ctx.fillStyle = 'rgba(236, 72, 153, 0.32)';
                ctx.fillRect(40, 270, 432, 26);
                ctx.fillStyle = 'rgba(0, 245, 255, 0.22)';
                ctx.fillRect(40, 380, 432, 32);

                const bCols1 = ['#38bdf8', '#818cf8', '#a855f7', '#00f5ff', '#ec4899', '#fef08a'];
                for (let x = 55; x < 460; x += 17) {
                    const col = bCols1[(x * 5) % bCols1.length];
                    const h = 26 + (x % 5) * 5;
                    ctx.fillStyle = col;
                    ctx.shadowColor = col;
                    ctx.shadowBlur = 12;
                    ctx.fillRect(x, 160 - h, 9, h);
                    ctx.fillRect(x + 2, 160 - h - 8, 5, 8);
                }

                const bCols2 = ['#ec4899', '#f43f5e', '#a855f7', '#c084fc', '#f472b6', '#38bdf8'];
                for (let x = 55; x < 460; x += 15) {
                    const col = bCols2[(x * 9) % bCols2.length];
                    const h = 28 + (x % 4) * 6;
                    ctx.fillStyle = col;
                    ctx.shadowColor = col;
                    ctx.shadowBlur = 14;
                    ctx.fillRect(x, 270 - h, 8, h);
                    ctx.fillRect(x + 2, 270 - h - 8, 4, 8);
                }
                ctx.shadowBlur = 0;

                ctx.fillStyle = '#0f172a';
                ctx.fillRect(20, 395, 472, 110);
                ctx.fillStyle = '#090d16';
                for (let hx = 90; hx < 440; hx += 85) {
                    ctx.beginPath();
                    ctx.arc(hx, 390, 22, Math.PI, 0);
                    ctx.fill();
                }

                const refl = ctx.createLinearGradient(0, 0, 512, 512);
                refl.addColorStop(0.2, 'rgba(255, 255, 255, 0.0)');
                refl.addColorStop(0.35, 'rgba(255, 255, 255, 0.12)');
                refl.addColorStop(0.42, 'rgba(255, 255, 255, 0.0)');
                refl.addColorStop(0.65, 'rgba(255, 255, 255, 0.08)');
                refl.addColorStop(0.72, 'rgba(255, 255, 255, 0.0)');
                ctx.fillStyle = refl;
                ctx.fillRect(0, 0, 512, 512);

            } else {
                // --- BAURUINE: Zersplittertes Glas, vernagelte Holzbretter, Dunkelheit ---
                ctx.fillStyle = '#0a0d14';
                ctx.fillRect(0, 0, 512, 512);

                // Zersplittertes Glas (Zackige Riss-Scherben)
                ctx.fillStyle = 'rgba(255, 255, 255, 0.08)';
                ctx.beginPath();
                ctx.moveTo(14, 14);
                ctx.lineTo(80, 14);
                ctx.lineTo(45, 90);
                ctx.lineTo(130, 40);
                ctx.lineTo(165, 14);
                ctx.lineTo(165, 180);
                ctx.lineTo(110, 140);
                ctx.lineTo(80, 260);
                ctx.lineTo(14, 200);
                ctx.closePath();
                ctx.fill();

                ctx.strokeStyle = 'rgba(255, 255, 255, 0.4)';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(80, 260);
                ctx.lineTo(130, 320);
                ctx.lineTo(90, 420);
                ctx.lineTo(14, 380);
                ctx.moveTo(130, 320);
                ctx.lineTo(165, 340);
                ctx.stroke();

                // Rechte Seite vernagelt mit Sperrholz
                ctx.fillStyle = '#5c3a21';
                ctx.fillRect(340, 14, 150, 484);
                ctx.strokeStyle = '#382314';
                ctx.lineWidth = 3;
                for (let py = 14; py < 500; py += 65) {
                    ctx.beginPath();
                    ctx.moveTo(340, py);
                    ctx.lineTo(490, py);
                    ctx.stroke();
                    ctx.fillStyle = '#1c1917';
                    ctx.fillRect(350, py + 12, 5, 5);
                    ctx.fillRect(475, py + 12, 5, 5);
                    ctx.fillStyle = '#5c3a21';
                }

                ctx.font = 'bold 42px sans-serif';
                ctx.fillStyle = 'rgba(236, 72, 153, 0.7)';
                ctx.fillText('STAY', 360, 220);
                ctx.fillText('OUT', 370, 275);
            }

            // Metall-Sprossen
            ctx.fillStyle = '#0a0d14';
            ctx.fillRect(0, 0, 512, 14);
            ctx.fillRect(0, 498, 512, 14);
            ctx.fillRect(0, 0, 14, 512);
            ctx.fillRect(498, 0, 14, 512);
            ctx.fillRect(165, 0, 14, 512);
            ctx.fillRect(333, 0, 14, 512);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getBrickTexture(isRuin = false) {
            const key = `brick_${isRuin ? 'ruin' : 'clean'}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = isRuin ? '#1c1917' : '#292524';
            ctx.fillRect(0, 0, 512, 512);

            const rows = 20;
            const rowH = 512 / rows;
            const brickW = 48;

            for (let r = 0; r < rows; r++) {
                const y = r * rowH;
                const offset = (r % 2) * (brickW / 2);
                for (let x = -brickW; x < 512 + brickW; x += brickW) {
                    const bx = x + offset;
                    const baseShade = isRuin ? 30 : 42;
                    const shade = baseShade + Math.floor(Math.random() * 16);
                    ctx.fillStyle = isRuin
                        ? `rgb(${shade + 8}, ${shade + 2}, ${shade})`
                        : `rgb(${shade + 12}, ${shade + 6}, ${shade + 2})`;
                    ctx.fillRect(bx + 1.5, y + 1.5, brickW - 3, rowH - 3);
                }
            }

            if (isRuin) {
                ctx.fillStyle = 'rgba(20, 35, 20, 0.55)';
                for (let i = 0; i < 18; i++) {
                    ctx.beginPath();
                    ctx.arc(Math.random() * 512, 450 + Math.random() * 60, 25 + Math.random() * 30, 0, Math.PI * 2);
                    ctx.fill();
                }
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(2, 2);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getClubCornerSignTexture(isOwned = false) {
            const key = `cornersign_${isOwned ? 'on' : 'off'}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 128;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = '#080a10';
            ctx.fillRect(0, 0, 256, 128);

            if (isOwned) {
                ctx.strokeStyle = '#00f5ff';
                ctx.shadowColor = '#00f5ff';
                ctx.shadowBlur = 14;
                ctx.lineWidth = 6;
                ctx.strokeRect(8, 8, 240, 112);

                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '900 52px "Arial Black", "Impact", sans-serif';
                ctx.shadowColor = '#ec4899';
                ctx.shadowBlur = 20;
                ctx.fillStyle = '#ec4899';
                ctx.fillText('CLUB', 128, 64);
                ctx.shadowBlur = 4;
                ctx.fillStyle = '#ffffff';
                ctx.fillText('CLUB', 128, 64);
            } else {
                ctx.strokeStyle = '#27272a';
                ctx.lineWidth = 5;
                ctx.strokeRect(8, 8, 240, 112);

                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.font = '900 52px "Arial Black", "Impact", sans-serif';
                ctx.fillStyle = '#3f3f46';
                ctx.fillText('CLUB', 128, 64);

                ctx.strokeStyle = '#09090b';
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.moveTo(80, 8);
                ctx.lineTo(140, 70);
                ctx.lineTo(190, 120);
                ctx.stroke();
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getSkylineTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['skyline']) return this._texCache['skyline'];

            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, 1024, 512);

            const blds = [
                { x: 20, w: 90, h: 320 },
                { x: 130, w: 120, h: 420 },
                { x: 270, w: 110, h: 360 },
                { x: 400, w: 130, h: 490 },
                { x: 550, w: 110, h: 410 },
                { x: 680, w: 95, h: 460 },
                { x: 795, w: 120, h: 350 },
                { x: 935, w: 80, h: 390 },
            ];

            blds.forEach(b => {
                ctx.fillStyle = '#090d16';
                ctx.fillRect(b.x, 512 - b.h, b.w, b.h);

                const cols = Math.floor(b.w / 12);
                const rows = Math.floor(b.h / 14);
                for (let r = 2; r < rows - 2; r++) {
                    for (let c = 1; c < cols - 1; c++) {
                        if (Math.random() < 0.65) {
                            ctx.fillStyle = Math.random() < 0.85 ? '#fef08a' : '#38bdf8';
                            ctx.fillRect(b.x + c * 12 + 2, 512 - b.h + r * 14 + 2, 7, 8);
                        }
                    }
                }
            });

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['skyline'] = tex;
            return tex;
        }

        // --- SONDERFELD-TEXTUR-GENERATOREN (FOTOREALISTISCHE SCHILDER & GRAFFITI) ---
        getDriveInFasciaTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['drivein_fascia']) return this._texCache['drivein_fascia'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');
            // 50s Diner Red & Gold Fassade
            ctx.fillStyle = '#b91c1c';
            ctx.fillRect(0, 0, 1024, 256);
            ctx.strokeStyle = '#f59e0b';
            ctx.lineWidth = 14;
            ctx.strokeRect(7, 7, 1010, 242);
            ctx.strokeStyle = '#fef08a';
            ctx.lineWidth = 4;
            ctx.strokeRect(18, 18, 988, 220);
            // Schriftzug DRIVE-IN
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 110px "Arial Black", "Impact", sans-serif';
            ctx.shadowColor = '#f59e0b';
            ctx.shadowBlur = 30;
            ctx.fillStyle = '#fef08a';
            ctx.fillText('DRIVE-IN', 512, 128);
            ctx.shadowBlur = 10;
            ctx.fillStyle = '#ffffff';
            ctx.fillText('DRIVE-IN', 512, 128);
            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['drivein_fascia'] = tex;
            return tex;
        }

        getMysteryVaultTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['mystery_vault']) return this._texCache['mystery_vault'];
            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');
            // Reich verziertes Gold-Metall mit Gravuren & Nieten
            const grad = ctx.createRadialGradient(256, 256, 50, 256, 256, 256);
            grad.addColorStop(0, '#fef08a');
            grad.addColorStop(0.5, '#f59e0b');
            grad.addColorStop(1, '#b45309');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, 512, 512);
            // Rahmen & Zierleisten
            ctx.strokeStyle = '#78350f';
            ctx.lineWidth = 16;
            ctx.strokeRect(8, 8, 496, 496);
            ctx.strokeStyle = '#fef08a';
            ctx.lineWidth = 6;
            ctx.strokeRect(26, 26, 460, 460);
            // Nieten an den Ecken
            const drawRivet = (x, y) => {
                ctx.fillStyle = '#451a03';
                ctx.beginPath(); ctx.arc(x, y, 14, 0, Math.PI * 2); ctx.fill();
                ctx.fillStyle = '#fef08a';
                ctx.beginPath(); ctx.arc(x - 3, y - 3, 8, 0, Math.PI * 2); ctx.fill();
            };
            drawRivet(45, 45); drawRivet(467, 45); drawRivet(45, 467); drawRivet(467, 467);
            // Zentrales Tresor-Drehrad
            ctx.fillStyle = '#78350f';
            ctx.beginPath(); ctx.arc(256, 256, 110, 0, Math.PI * 2); ctx.fill();
            ctx.fillStyle = '#f59e0b';
            ctx.beginPath(); ctx.arc(256, 256, 92, 0, Math.PI * 2); ctx.fill();
            ctx.strokeStyle = '#451a03';
            ctx.lineWidth = 10;
            for (let a = 0; a < 6; a++) {
                const ang = a * Math.PI / 3;
                ctx.beginPath();
                ctx.moveTo(256, 256);
                ctx.lineTo(256 + Math.cos(ang) * 90, 256 + Math.sin(ang) * 90);
                ctx.stroke();
            }
            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['mystery_vault'] = tex;
            return tex;
        }

        getHoodFmGraffitiTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['hoodfm_graffiti']) return this._texCache['hoodfm_graffiti'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');
            // Ziegelwand-Hintergrund
            ctx.fillStyle = '#7c2d12';
            ctx.fillRect(0, 0, 1024, 512);
            ctx.strokeStyle = '#431407';
            ctx.lineWidth = 2;
            for (let y = 0; y < 512; y += 24) {
                ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(1024, y); ctx.stroke();
                const offset = (y / 24) % 2 === 0 ? 0 : 32;
                for (let x = offset; x < 1024; x += 64) {
                    ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x, y + 24); ctx.stroke();
                }
            }
            // Farbige Spray-Wolke / Splatter um das Logo (Cyan, Magenta, Violett)
            const splashGrad = ctx.createRadialGradient(512, 256, 80, 512, 256, 380);
            splashGrad.addColorStop(0, 'rgba(6, 182, 212, 0.85)');
            splashGrad.addColorStop(0.4, 'rgba(236, 72, 153, 0.7)');
            splashGrad.addColorStop(0.7, 'rgba(147, 51, 234, 0.55)');
            splashGrad.addColorStop(1, 'rgba(124, 45, 18, 0)');
            ctx.fillStyle = splashGrad;
            ctx.fillRect(100, 40, 824, 432);

            // Kopfhörer-Bogen über dem Logo
            ctx.strokeStyle = '#0284c7';
            ctx.lineWidth = 26;
            ctx.beginPath();
            ctx.arc(512, 230, 230, Math.PI * 0.95, Math.PI * 2.05);
            ctx.stroke();
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(260, 200, 45, 90);
            ctx.fillRect(719, 200, 45, 90);

            // HOOD FM Schriftzug im Wildstyle-Comic-Look
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 135px "Arial Black", "Impact", sans-serif';
            ctx.fillStyle = '#0f172a';
            ctx.fillText('HOOD FM', 522, 262);
            ctx.strokeStyle = '#000000';
            ctx.lineWidth = 18;
            ctx.strokeText('HOOD FM', 512, 250);
            const textGrad = ctx.createLinearGradient(0, 180, 0, 320);
            textGrad.addColorStop(0, '#fef08a');
            textGrad.addColorStop(0.5, '#f97316');
            textGrad.addColorStop(1, '#dc2626');
            ctx.fillStyle = textGrad;
            ctx.fillText('HOOD FM', 512, 250);

            // Subtitel & Frequenz
            ctx.font = '900 36px "Arial Black", sans-serif';
            ctx.fillStyle = '#ffffff';
            ctx.fillText('104.2 MHZ • ON AIR', 512, 345);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['hoodfm_graffiti'] = tex;
            return tex;
        }

        getTollPlazaSignTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['toll_sign']) return this._texCache['toll_sign'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#090d16';
            ctx.fillRect(0, 0, 1024, 256);
            ctx.strokeStyle = '#334155';
            ctx.lineWidth = 8;
            ctx.strokeRect(4, 4, 1016, 248);

            const lanes = [
                { type: 'cross', x: 130 },
                { type: 'cross', x: 385 },
                { type: 'telepass_arrow', x: 640 },
                { type: 'cash_arrow', x: 895 },
            ];
            lanes.forEach(l => {
                ctx.fillStyle = '#0f172a';
                ctx.fillRect(l.x - 100, 25, 200, 206);
                ctx.strokeStyle = '#1e293b';
                ctx.lineWidth = 4;
                ctx.strokeRect(l.x - 100, 25, 200, 206);

                if (l.type === 'cross') {
                    ctx.shadowColor = '#ef4444';
                    ctx.shadowBlur = 20;
                    ctx.strokeStyle = '#ef4444';
                    ctx.lineWidth = 22;
                    ctx.beginPath();
                    ctx.moveTo(l.x - 55, 60); ctx.lineTo(l.x + 55, 196);
                    ctx.moveTo(l.x + 55, 60); ctx.lineTo(l.x - 55, 196);
                    ctx.stroke();
                } else {
                    ctx.shadowColor = '#22c55e';
                    ctx.shadowBlur = 20;
                    ctx.fillStyle = '#22c55e';
                    ctx.beginPath();
                    ctx.moveTo(l.x, 205);
                    ctx.lineTo(l.x - 45, 140);
                    ctx.lineTo(l.x - 18, 140);
                    ctx.lineTo(l.x - 18, 80);
                    ctx.lineTo(l.x + 18, 80);
                    ctx.lineTo(l.x + 18, 140);
                    ctx.lineTo(l.x + 45, 140);
                    ctx.closePath();
                    ctx.fill();

                    if (l.type === 'telepass_arrow') {
                        ctx.fillStyle = '#f97316';
                        ctx.fillRect(l.x - 85, 38, 45, 45);
                        ctx.fillStyle = '#ffffff';
                        ctx.font = '900 32px sans-serif';
                        ctx.fillText('t', l.x - 63, 72);
                    }
                }
                ctx.shadowBlur = 0;
            });

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['toll_sign'] = tex;
            return tex;
        }

        getHazardStripeTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['hazard_stripe']) return this._texCache['hazard_stripe'];
            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#eab308';
            ctx.fillRect(0, 0, 256, 256);
            ctx.fillStyle = '#18181b';
            for (let i = -256; i < 512; i += 64) {
                ctx.beginPath();
                ctx.moveTo(i, 0);
                ctx.lineTo(i + 32, 0);
                ctx.lineTo(i + 32 + 256, 256);
                ctx.lineTo(i + 256, 256);
                ctx.closePath();
                ctx.fill();
            }
            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(2, 1);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['hazard_stripe'] = tex;
            return tex;
        }

        getWorkshopSignTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['workshop_sign']) return this._texCache['workshop_sign'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, 1024, 256);
            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 10;
            ctx.strokeRect(5, 5, 1014, 246);

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 88px "Arial Black", "Impact", sans-serif';
            ctx.fillStyle = '#0f172a';
            ctx.fillText('KFZ-Meisterbetrieb', 512, 95);

            ctx.font = '700 48px sans-serif';
            ctx.fillStyle = '#0284c7';
            ctx.fillText('Reparatur aller Marken', 512, 178);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['workshop_sign'] = tex;
            return tex;
        }

        getViewpointSignTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['viewpoint_sign']) return this._texCache['viewpoint_sign'];
            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#1d4ed8';
            ctx.beginPath();
            ctx.roundRect(16, 16, 480, 480, 45);
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 20;
            ctx.stroke();

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 240px "Arial Black", sans-serif';
            ctx.fillStyle = '#ffffff';
            ctx.fillText('P', 256, 210);

            ctx.fillStyle = '#ffffff';
            ctx.fillRect(60, 360, 392, 70);
            ctx.font = '900 40px sans-serif';
            ctx.fillStyle = '#1d4ed8';
            ctx.fillText('Aussichtspunkt', 256, 398);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['viewpoint_sign'] = tex;
            return tex;
        }

        getPanoramatafelTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['panoramatafel']) return this._texCache['panoramatafel'];
            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 256);
            grad.addColorStop(0, '#38bdf8');
            grad.addColorStop(0.5, '#0284c7');
            grad.addColorStop(1, '#0369a1');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, 512, 256);
            ctx.fillStyle = '#15803d';
            ctx.beginPath();
            ctx.ellipse(120, 150, 90, 35, 0, 0, Math.PI * 2);
            ctx.ellipse(380, 160, 110, 40, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.moveTo(250, 140); ctx.lineTo(260, 120); ctx.lineTo(262, 140); ctx.fill();
            ctx.strokeStyle = '#78350f';
            ctx.lineWidth = 14;
            ctx.strokeRect(7, 7, 498, 242);
            ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
            ctx.fillRect(20, 200, 472, 42);
            ctx.fillStyle = '#0f172a';
            ctx.font = '700 24px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('PANORAMA • HOOD BAY', 256, 228);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['panoramatafel'] = tex;
            return tex;
        }

        // --- A. BARS & CLUBS (Exakte 1:1 Umsetzung der fotorealistischen Referenzbilder!) ---
        renderClubPlot(group, ownerColor, isMortgaged, tileName) {
            const isOwned = !!ownerColor;
            const hex = ownerColor || '#00f5ff';

            // 1. FUNDAMENT & BÜRGERSTEIG
            const baseGeo = new THREE.BoxGeometry(22, 0.35, 19);
            const baseMat = new THREE.MeshStandardMaterial({
                color: isOwned ? 0x181e29 : 0x141820,
                roughness: 0.8,
            });
            const base = new THREE.Mesh(baseGeo, baseMat);
            base.position.set(0, 0.18, 0);
            base.receiveShadow = true;
            group.add(base);

            // Bürgersteig vor dem Gebäude
            const walkGeo = new THREE.BoxGeometry(7, 0.08, 18);
            const walkMat = new THREE.MeshStandardMaterial({
                color: isOwned ? 0x94a3b8 : 0x475569,
                roughness: isOwned ? 0.65 : 0.95,
            });
            const walk = new THREE.Mesh(walkGeo, walkMat);
            walk.position.set(-5.5, 0.4, 0);
            walk.receiveShadow = true;
            group.add(walk);

            // 2. DAS GEBÄUDE (18m breit, 6.2m hoch, 12m tief)
            const bldGroup = new THREE.Group();
            bldGroup.position.set(4.0, 0, 0);

            // Unterer Mauerwerk-Sockel mit Ziegeltextur
            const brickMat = new THREE.MeshStandardMaterial({
                map: this.getBrickTexture(!isOwned),
                roughness: 0.9,
            });
            const brickBase = new THREE.Mesh(new THREE.BoxGeometry(11.8, 1.8, 18), brickMat);
            brickBase.position.set(0, 0.9 + 0.35, 0);
            brickBase.castShadow = true;
            bldGroup.add(brickBase);

            // Oberer Beton-/Fassadenkörper
            const concreteMat = new THREE.MeshStandardMaterial({
                color: isOwned ? 0x22262f : 0x272c35,
                roughness: 0.85,
            });
            const mainBody = new THREE.Mesh(new THREE.BoxGeometry(11.8, 4.4, 18), concreteMat);
            mainBody.position.set(0, 4.0 + 0.35, 0);
            mainBody.castShadow = true;
            bldGroup.add(mainBody);

            // Beton-Pfeiler an den Ecken
            const pillarMat = new THREE.MeshStandardMaterial({ color: isOwned ? 0x333b49 : 0x3f4654, roughness: 0.75 });
            [-8.9, 8.9].forEach(pz => {
                const pil = new THREE.Mesh(new THREE.BoxGeometry(1.2, 6.4, 0.8), pillarMat);
                pil.position.set(-5.8, 3.2 + 0.35, pz);
                pil.castShadow = true;
                bldGroup.add(pil);
            });

            // 3. FENSTER LINKS & RECHTS
            const winMat = new THREE.MeshBasicMaterial({
                map: this.getBarWindowTexture(isOwned, hex),
            });

            // Linkes Fenster
            const winLeft = new THREE.Mesh(new THREE.PlaneGeometry(5.4, 3.2), winMat);
            winLeft.position.set(-6.02, 3.0 + 0.35, -4.8);
            winLeft.rotation.y = -Math.PI / 2;
            bldGroup.add(winLeft);

            // Rechtes Fenster
            const winRight = new THREE.Mesh(new THREE.PlaneGeometry(5.4, 3.2), winMat);
            winRight.position.set(-6.02, 3.0 + 0.35, 4.8);
            winRight.rotation.y = -Math.PI / 2;
            bldGroup.add(winRight);

            // 4. HAUPTEINGANG
            const doorMat = new THREE.MeshStandardMaterial({ color: 0x111317, roughness: 0.6 });
            const doors = new THREE.Mesh(new THREE.BoxGeometry(0.2, 3.4, 2.8), doorMat);
            doors.position.set(-5.6, 1.7 + 0.35, 0);
            bldGroup.add(doors);

            // Türgriffe aus gebürstetem Edelstahl
            const handleMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.9, roughness: 0.2 });
            [-0.15, 0.15].forEach(dz => {
                const handle = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 1.2, 8), handleMat);
                handle.position.set(-5.75, 1.7 + 0.35, dz);
                bldGroup.add(handle);
            });

            // Wenn Ruine: 3 Holzbretter schräg über die Türen genagelt!
            if (!isOwned) {
                const woodBoardMat = new THREE.MeshStandardMaterial({ color: 0x713f12, roughness: 0.9 });
                [-0.4, 0.0, 0.4].forEach((yo, bIdx) => {
                    const board = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.35, 3.2), woodBoardMat);
                    board.position.set(-5.8, 1.8 + yo + 0.35, 0);
                    board.rotation.x = (bIdx - 1) * 0.12;
                    bldGroup.add(board);
                });
            }

            // 5. DAS VORDACH / BALDACHIN ÜBER DEM EINGANG
            const canopyMat = new THREE.MeshStandardMaterial({ color: 0x181b22, roughness: 0.6 });
            const canopy = new THREE.Mesh(new THREE.BoxGeometry(2.6, 0.3, 4.2), canopyMat);
            canopy.position.set(-7.1, 3.8 + 0.35, 0);
            canopy.castShadow = true;
            bldGroup.add(canopy);

            if (isOwned) {
                // Zwei leuchtende Neon-Kantenstreifen am Vordach (Cyan & Magenta wie im Bild!)
                const neonCyan = new THREE.Mesh(new THREE.BoxGeometry(2.65, 0.06, 4.25), new THREE.MeshBasicMaterial({ color: 0x00f5ff }));
                neonCyan.position.set(-7.1, 3.7 + 0.35, 0);
                bldGroup.add(neonCyan);

                const neonPink = new THREE.Mesh(new THREE.BoxGeometry(2.65, 0.06, 4.25), new THREE.MeshBasicMaterial({ color: 0xec4899 }));
                neonPink.position.set(-7.1, 3.9 + 0.35, 0);
                bldGroup.add(neonPink);

                // 3 warme Downlights unter dem Vordach
                [-1.2, 0, 1.2].forEach(lz => {
                    const spot = new THREE.Mesh(new THREE.SphereGeometry(0.12, 8, 8), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
                    spot.position.set(-7.1, 3.65 + 0.35, lz);
                    bldGroup.add(spot);
                });
            }

            // 6. DAS GROSSE NEON-FASSADENSCHILD "The Neon Lounge"
            const fasciaMat = new THREE.MeshBasicMaterial({
                map: this.getNeonFasciaTexture(tileName, isOwned, hex),
            });
            const fascia = new THREE.Mesh(new THREE.PlaneGeometry(18.2, 1.9), fasciaMat);
            fascia.position.set(-6.02, 5.25 + 0.35, 0);
            fascia.rotation.y = -Math.PI / 2;
            bldGroup.add(fascia);

            // Dachkanten-Neonlinie
            if (isOwned) {
                const roofNeon = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.1, 18.2), new THREE.MeshBasicMaterial({ color: 0xec4899 }));
                roofNeon.position.set(-6.05, 6.2 + 0.35, 0);
                bldGroup.add(roofNeon);
            }

            // 7. ECK-SCHILD "CLUB" AN DER RECHTEN AUSSENWAND
            const clubSignMat = new THREE.MeshBasicMaterial({
                map: this.getClubCornerSignTexture(isOwned),
            });
            const clubSign = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.8, 0.15), clubSignMat);
            clubSign.position.set(-5.6, 4.4 + 0.35, 9.15);
            bldGroup.add(clubSign);

            // 8. INDUSTRIELLE DACH-ELEMENTE (HVAC-Klimageräte & Abzugsrohre wie im Bild!)
            const hvacMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.6, roughness: 0.6 });
            // HVAC 1
            const hvac1 = new THREE.Mesh(new THREE.BoxGeometry(2.4, 1.6, 2.0), hvacMat);
            hvac1.position.set(0, 7.0 + 0.35, -3.5);
            hvac1.castShadow = true;
            bldGroup.add(hvac1);

            // HVAC 2
            const hvac2 = new THREE.Mesh(new THREE.BoxGeometry(1.8, 1.3, 1.6), hvacMat);
            hvac2.position.set(2, 6.8 + 0.35, 2.5);
            hvac2.castShadow = true;
            bldGroup.add(hvac2);

            // Geschwungenes Abluftrohr
            const vent = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 2.2, 12), hvacMat);
            vent.position.set(-1, 7.3 + 0.35, 0.5);
            bldGroup.add(vent);

            const ventCap = new THREE.Mesh(new THREE.ConeGeometry(0.6, 0.4, 12), hvacMat);
            ventCap.position.set(-1, 8.5 + 0.35, 0.5);
            bldGroup.add(ventCap);

            // 9. VORBEREICH / BÜRGERSTEIG-DETAILS
            if (isOwned) {
                // Roter VIP-Teppichläufer
                const carpet = new THREE.Mesh(new THREE.BoxGeometry(4.5, 0.04, 1.8), new THREE.MeshStandardMaterial({ color: 0x9f1239, roughness: 0.8 }));
                carpet.position.set(-3.5, 0.44, 0);
                bldGroup.add(carpet);

                // Chrom-Kordelständer & schwarze Seile
                const brassMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, metalness: 0.95, roughness: 0.15 });
                [-1.2, 1.2].forEach(oz => {
                    [-2.5, -4.8].forEach(ox => {
                        const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.035, 0.035, 0.9, 8), brassMat);
                        pole.position.set(ox, 0.85 + 0.35, oz);
                        bldGroup.add(pole);

                        const ball = new THREE.Mesh(new THREE.SphereGeometry(0.07, 8, 8), brassMat);
                        ball.position.set(ox, 1.3 + 0.35, oz);
                        bldGroup.add(ball);
                    });
                });
            } else {
                // Ruine: Trümmer, Schutt und wildes Gras auf dem Gehweg
                const weedMat = new THREE.MeshStandardMaterial({ color: 0x3f6212, roughness: 0.9 });
                for (let w = 0; w < 16; w++) {
                    const weed = new THREE.Mesh(new THREE.SphereGeometry(0.3 + Math.random() * 0.3, 6, 6), weedMat);
                    weed.scale.set(0.6, 1.4, 0.6);
                    weed.position.set(-7.5 + (Math.random() - 0.5) * 3, 0.5, (Math.random() - 0.5) * 16);
                    bldGroup.add(weed);
                }

                // Betontrümmer
                const rubbleMat = new THREE.MeshStandardMaterial({ color: 0x71717a, roughness: 0.95 });
                for (let rb = 0; rb < 8; rb++) {
                    const chunk = new THREE.Mesh(new THREE.BoxGeometry(0.4 + Math.random() * 0.5, 0.25, 0.4 + Math.random() * 0.5), rubbleMat);
                    chunk.position.set(-6.5 + (Math.random() - 0.5) * 4, 0.5, (Math.random() - 0.5) * 12);
                    chunk.rotation.set(Math.random() * 0.4, Math.random() * 3, Math.random() * 0.4);
                    bldGroup.add(chunk);
                }
            }

            // 10. HINTERGRUND: SKYLINE-HOCHHÄUSER MIT LEUCHTENDEN FENSTERN
            const skylineMat = new THREE.MeshBasicMaterial({
                map: this.getSkylineTexture(),
                transparent: true,
                opacity: 0.75,
            });
            const skylineMesh = new THREE.Mesh(new THREE.PlaneGeometry(36, 16), skylineMat);
            skylineMesh.position.set(11.0, 10.0, 0);
            skylineMesh.rotation.y = -Math.PI / 2;
            bldGroup.add(skylineMesh);

            group.add(bldGroup);
        }

        // --- B. STRÄNDE (Verfallenes Wrack & Ruine bei Leerstand -> Aktives Luxus-Strandbad bei Besitz) ---
        renderBeachPlot(group, ownerColor, isMortgaged, tileName) {
            const isOwned = !!ownerColor;

            if (!isOwned) {
                // 1. DIE STRAND-BAURUINE (Verfallener Steg, eingestürzte Strandbude, Treibgut & Absperrung)
                const sandMat = new THREE.MeshStandardMaterial({ color: 0xd97706, roughness: 0.95 });
                const woodRuinMat = new THREE.MeshStandardMaterial({ color: 0x573a08, roughness: 0.9 });
                const strawMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.95 });

                // Verwilderter Sandboden mit Gestrüpp
                const plot = new THREE.Mesh(new THREE.BoxGeometry(22, 0.35, 19), sandMat);
                plot.position.set(0, 0.18, 0);
                plot.receiveShadow = true;
                group.add(plot);

                // Morsche, schiefe Pfähle eines alten Holzstegs
                for (let p = 0; p < 4; p++) {
                    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.14, 2.2, 6), woodRuinMat);
                    post.position.set(-2 + p * 2.2, 1.0, -4);
                    post.rotation.z = (Math.random() - 0.5) * 0.3;
                    group.add(post);
                }

                // Eingestürzte Tiki-Strandbude: Schiefe Pfähle & halb herabgefallenes Strohdach
                const hutPost1 = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 3.2, 8), woodRuinMat);
                hutPost1.position.set(2, 1.6, 2);
                hutPost1.rotation.z = 0.2;
                group.add(hutPost1);

                const hutPost2 = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 2.2, 8), woodRuinMat);
                hutPost2.position.set(5, 1.1, 2);
                hutPost2.rotation.z = -0.3;
                group.add(hutPost2);

                // Eingestürztes, schiefes Strohdach
                const collapsedRoof = new THREE.Mesh(new THREE.BoxGeometry(5.2, 0.3, 4.5), strawMat);
                collapsedRoof.position.set(3.5, 2.2, 2);
                collapsedRoof.rotation.set(0.2, 0.1, -0.45);
                collapsedRoof.castShadow = true;
                group.add(collapsedRoof);

                // Angespülte Kiste & rostiges Ölfass im Sand
                const crate = new THREE.Mesh(new THREE.BoxGeometry(1.2, 1.0, 1.2), woodRuinMat);
                crate.position.set(-1, 0.5, 1);
                crate.rotation.y = 0.4;
                group.add(crate);

                const barrel = new THREE.Mesh(new THREE.CylinderGeometry(0.4, 0.4, 1.1, 8), new THREE.MeshStandardMaterial({ color: 0x9a3412 }));
                barrel.position.set(0.8, 0.55, 3.5);
                barrel.rotation.z = Math.PI / 2;
                group.add(barrel);

                // Schiefes Holzschild "STRAND GESCHLOSSEN • SANIERUNGSBEDÜRFTIG"
                const sign = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.1, 2.4), new THREE.MeshStandardMaterial({ color: 0xca8a04 }));
                sign.position.set(-6.5, 1.8, 0);
                sign.rotation.z = 0.15;
                group.add(sign);

            } else {
                // 2. DAS WIEDERHERGESTELLTE, AKTIVE LUXUS-STRANDBAD
                const hex = ownerColor;
                const goldSandMat = new THREE.MeshStandardMaterial({ color: 0xfef08a, roughness: 0.9 });
                const seaEdgeMat = new THREE.MeshStandardMaterial({ color: 0x06b6d4, roughness: 0.2, metalness: 0.3 });
                const bambooMat = new THREE.MeshStandardMaterial({ color: 0xb45309, roughness: 0.7 });
                const thatchMat = new THREE.MeshStandardMaterial({ color: 0xd97706, roughness: 0.95 });
                const playerMat = new THREE.MeshStandardMaterial({ color: hex, roughness: 0.4 });

                // Feiner goldener Sandstrand mit türkisem Brandungssaum
                const sand = new THREE.Mesh(new THREE.BoxGeometry(22, 0.35, 19), goldSandMat);
                sand.position.set(0, 0.18, 0);
                sand.receiveShadow = true;
                group.add(sand);

                const seaEdge = new THREE.Mesh(new THREE.BoxGeometry(4, 0.38, 19), seaEdgeMat);
                seaEdge.position.set(9, 0.19, 0);
                group.add(seaEdge);

                // Karibische Tiki-Cocktailbar
                const barCounter = new THREE.Mesh(new THREE.BoxGeometry(3.5, 1.2, 4.5), bambooMat);
                barCounter.position.set(1.5, 0.8, 0);
                barCounter.castShadow = true;
                group.add(barCounter);

                // Schilfdach über der Bar
                const roof = new THREE.Mesh(new THREE.ConeGeometry(3.8, 1.8, 8), thatchMat);
                roof.position.set(1.5, 3.2, 0);
                roof.castShadow = true;
                group.add(roof);

                // Bunte Lichterkette unter dem Dach
                for (let l = 0; l < 4; l++) {
                    const lamp = new THREE.Mesh(new THREE.SphereGeometry(0.12, 8, 8), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
                    lamp.position.set(1.5 + (l - 1.5) * 0.8, 2.2, 1.8);
                    group.add(lamp);
                }

                // 2 Große, bunte Sonnenschirme in Spielerfarbe
                [-3.5, 3.5].forEach(oz => {
                    const umbrellaGroup = new THREE.Group();
                    umbrellaGroup.position.set(-3.5, 0, oz);

                    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.8), bambooMat);
                    pole.position.y = 1.4;
                    umbrellaGroup.add(pole);

                    const canopy = new THREE.Mesh(new THREE.ConeGeometry(2.1, 0.9, 12), playerMat);
                    canopy.position.y = 2.7;
                    canopy.castShadow = true;
                    umbrellaGroup.add(canopy);

                    // Gemütliche Strand-Liegestühle
                    const lounger = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.25, 0.8), new THREE.MeshStandardMaterial({ color: 0xf8fafc }));
                    lounger.position.set(0.5, 0.35, 0.4);
                    lounger.rotation.y = 0.2;
                    umbrellaGroup.add(lounger);

                    group.add(umbrellaGroup);
                });

                // Bunte Surfbretter an der Tiki-Bar gelehnt
                const boardGeo = new THREE.BoxGeometry(0.1, 2.4, 0.6);
                const surfboard1 = new THREE.Mesh(boardGeo, playerMat);
                surfboard1.position.set(-0.5, 1.1, 1.8);
                surfboard1.rotation.z = -0.25;
                group.add(surfboard1);

                const surfboard2 = new THREE.Mesh(boardGeo, new THREE.MeshStandardMaterial({ color: 0x06b6d4 }));
                surfboard2.position.set(-0.5, 1.1, 2.3);
                surfboard2.rotation.z = -0.2;
                group.add(surfboard2);

                // Edle weiße Flagge mit Wimpel in Spielerfarbe
                const flagMast = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 6), new THREE.MeshStandardMaterial({ color: 0xffffff }));
                flagMast.position.set(-6.5, 3.0, -3.5);
                group.add(flagMast);

                const flag = new THREE.Mesh(new THREE.BoxGeometry(0.04, 1.2, 1.8), playerMat);
                flag.position.set(-6.5, 5.2, -4.3);
                group.add(flag);
            }
        }

        // --- C. STRASSEN (Level 0: Natürliche Wiese mit Schild -> Level 1–5: Villen & Hotels) ---
        renderStreetPlot(group, level, ownerColor, isMortgaged, tileName) {
            const hexColor = ownerColor || '#3b82f6';
            const playerMat = new THREE.MeshStandardMaterial({ color: hexColor, roughness: 0.45, metalness: 0.2 });

            // Grundstücks-Boden: Gepflegter, saftiger grüner Rasen
            const plotMat = new THREE.MeshStandardMaterial({
                color: level === 0 ? 0x22c55e : 0x16a34a,
                roughness: 0.85,
            });
            const plotGeo = new THREE.BoxGeometry(20, 0.35, 18);
            const plot = new THREE.Mesh(plotGeo, plotMat);
            plot.position.set(0, 0.18, 0);
            plot.receiveShadow = true;
            group.add(plot);

            // STUFE 0: WILDNIS & VERKAUFSSCHILD (Sonnige Blumenwiese mit runden Kieselsteinen)
            if (level === 0) {
                // Weiche, abgerundete Natursteine im Rasen
                const rockMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.85 });
                const r1 = new THREE.Mesh(new THREE.SphereGeometry(0.8, 10, 8), rockMat);
                r1.position.set(-2.5, 0.4, -2.5);
                r1.scale.set(1.2, 0.6, 1.0);
                group.add(r1);

                const r2 = new THREE.Mesh(new THREE.SphereGeometry(0.6, 10, 8), rockMat);
                r2.position.set(3, 0.35, 3.2);
                r2.scale.set(1.0, 0.5, 1.3);
                group.add(r2);

                // Bunte Wildblumen im Gras
                const flowerColors = [0xfef08a, 0xf43f5e, 0xa855f7, 0xffffff];
                for (let fl = 0; fl < 12; fl++) {
                    const flMat = new THREE.MeshBasicMaterial({ color: flowerColors[fl % flowerColors.length] });
                    const flower = new THREE.Mesh(new THREE.SphereGeometry(0.14, 6, 6), flMat);
                    flower.position.set((Math.random() - 0.5) * 12, 0.42, (Math.random() - 0.5) * 12);
                    group.add(flower);
                }

                // Hochwertiges Holzschild "GRUNDSTÜCK ZU VERKAUFEN"
                const woodMat = new THREE.MeshStandardMaterial({ color: 0x92400e, roughness: 0.8 });
                const post = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 2.8), woodMat);
                post.position.set(-6, 1.4, 0);
                group.add(post);

                const board = new THREE.Mesh(new THREE.BoxGeometry(0.12, 1.3, 3.2), new THREE.MeshStandardMaterial({ color: 0xfef08a, roughness: 0.7 }));
                board.position.set(-6, 2.2, 0);
                board.castShadow = true;
                group.add(board);
                return;
            }

            // STUFE 1: ERSCHLOSSEN (Gepflegter Vorgartenzaun, Flagge & Pflasterweg)
            if (level >= 1) {
                // Pflasterweg von der Straße
                const pathMat = new THREE.MeshStandardMaterial({ color: 0xcfd8dc, roughness: 0.8 });
                const walkPath = new THREE.Mesh(new THREE.BoxGeometry(7, 0.08, 2.4), pathMat);
                walkPath.position.set(-5, 0.38, 0);
                group.add(walkPath);

                const fenceGeo = new THREE.BoxGeometry(0.18, 1.0, 16);
                const fence = new THREE.Mesh(fenceGeo, playerMat);
                fence.position.set(-7.5, 0.75, 0);
                fence.castShadow = true;
                group.add(fence);

                const flagPole = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 5), new THREE.MeshStandardMaterial({ color: 0xf8fafc }));
                flagPole.position.set(-6.5, 2.5, -3.5);
                group.add(flagPole);

                const flag = new THREE.Mesh(new THREE.BoxGeometry(0.04, 1.0, 1.5), playerMat);
                flag.position.set(-6.5, 4.4, -4.2);
                group.add(flag);
            }

            // STUFE 2–4: 1 BIS 3 MODERNE SKANDINAVISCHE KÜSTENVILLEN
            if (level >= 2 && level <= 4) {
                const count = level - 1;
                const offsetsZ = count === 1 ? [0] : count === 2 ? [-4.5, 4.5] : [-5.5, 0, 5.5];

                offsetsZ.forEach(oz => {
                    const houseGroup = new THREE.Group();
                    houseGroup.position.set(1.8, 0, oz);

                    // Weißer Stilmauer-Körper
                    const bodyGeo = new THREE.BoxGeometry(7.5, 4.2, 4.8);
                    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.35 });
                    const body = new THREE.Mesh(bodyGeo, bodyMat);
                    body.position.set(0, 2.2, 0);
                    body.castShadow = true;
                    body.receiveShadow = true;
                    houseGroup.add(body);

                    // Modernes Pult-/Satteldach in Spielerfarbe
                    const roofGeo = new THREE.BoxGeometry(8.2, 0.5, 5.4);
                    const roof = new THREE.Mesh(roofGeo, playerMat);
                    roof.position.set(0, 4.4, 0);
                    roof.castShadow = true;
                    houseGroup.add(roof);

                    // Große Panorama-Glasfront mit Himmelsreflexion
                    const glassGeo = new THREE.BoxGeometry(0.1, 2.4, 3.2);
                    const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.8 });
                    const glass = new THREE.Mesh(glassGeo, glassMat);
                    glass.position.set(-3.8, 2.2, 0);
                    houseGroup.add(glass);

                    group.add(houseGroup);
                });
            }

            // STUFE 5: LUXUS-RESORT MIT PRIVAT-POOL & PALME
            if (level === 5) {
                const hotelGroup = new THREE.Group();

                const mainGeo = new THREE.BoxGeometry(11, 7.5, 11);
                const mainMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.3, metalness: 0.2 });
                const main = new THREE.Mesh(mainGeo, mainMat);
                main.position.set(2, 3.9, 0);
                main.castShadow = true;
                hotelGroup.add(main);

                const roofDeck = new THREE.Mesh(new THREE.BoxGeometry(12, 0.4, 12), playerMat);
                roofDeck.position.set(2, 7.8, 0);
                hotelGroup.add(roofDeck);

                const glassGeo = new THREE.BoxGeometry(0.2, 5.5, 8);
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.8 });
                const glass = new THREE.Mesh(glassGeo, glassMat);
                glass.position.set(-3.6, 4.0, 0);
                hotelGroup.add(glass);

                // Türkiser Swimmingpool im Garten
                const poolGeo = new THREE.BoxGeometry(5.2, 0.25, 7.5);
                const poolMat = new THREE.MeshBasicMaterial({ color: 0x06b6d4 });
                const pool = new THREE.Mesh(poolGeo, poolMat);
                pool.position.set(-6.5, 0.4, 0);
                hotelGroup.add(pool);

                group.add(hotelGroup);
            }
        }

        // --- D. SPEZIELLE FELDER (1:1 UMSETZUNG DER REFERENZBILDER DES USERS!) ---
        renderSpecialPlot(group, tileType, tileName, tileIndex = 0) {
            // Grundstücksboden
            const plotMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.85 });
            const plot = new THREE.Mesh(new THREE.BoxGeometry(20, 0.35, 18), plotMat);
            plot.position.set(0, 0.18, 0);
            plot.receiveShadow = true;
            group.add(plot);

            // Gehweg vor dem Areal
            const walk = new THREE.Mesh(new THREE.BoxGeometry(7, 0.08, 18), new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.8 }));
            walk.position.set(-5.5, 0.4, 0);
            walk.receiveShadow = true;
            group.add(walk);

            const isHandschuhfach = [7, 22, 36].includes(tileIndex) || tileName.toLowerCase().includes('handschuhfach');
            const isRadioTicker = [2, 17, 33].includes(tileIndex) || tileName.toLowerCase().includes('radio');

            if (isHandschuhfach) {
                // =============================================================
                // 1. HANDSCHUHFACH 🗃️ — RETRO DRIVE-IN MIT SCHWEBENDEM GOLD-TRESOR (BILD 1)
                // =============================================================
                const driveInGroup = new THREE.Group();
                driveInGroup.position.set(3.5, 0, 0);

                // Asphalt-Zufahrt mit Drive-Through Markierungen
                const lane = new THREE.Mesh(new THREE.BoxGeometry(11, 0.04, 16), new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.8 }));
                lane.position.set(-2.0, 0.38, 0);
                driveInGroup.add(lane);

                // Weiß markierte Haltelinien & Chevrons
                for (let z = -6; z <= 6; z += 3) {
                    const mark = new THREE.Mesh(new THREE.BoxGeometry(2.5, 0.01, 0.25), new THREE.MeshBasicMaterial({ color: 0xf8fafc }));
                    mark.position.set(-4.5, 0.41, z);
                    driveInGroup.add(mark);
                }

                // Hauptgebäude: 50s Diner-Look (Creme & Karminrot)
                const dinerBody = new THREE.Mesh(new THREE.BoxGeometry(8.2, 4.2, 9.6), new THREE.MeshStandardMaterial({ color: 0xfef3c7, roughness: 0.45 }));
                dinerBody.position.set(1.5, 2.3, 0);
                dinerBody.castShadow = true;
                driveInGroup.add(dinerBody);

                // Roter Sockelstreifen
                const redBase = new THREE.Mesh(new THREE.BoxGeometry(8.4, 1.2, 9.8), new THREE.MeshStandardMaterial({ color: 0xb91c1c, roughness: 0.4 }));
                redBase.position.set(1.5, 0.9, 0);
                driveInGroup.add(redBase);

                // Großes Panorama-Glasfenster mit warmem Innenlicht
                const windowMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
                const frontWin = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.8, 7.8), windowMat);
                frontWin.position.set(-2.65, 2.6, 0);
                driveInGroup.add(frontWin);

                // Weites, geschwungenes Vordach über der Drive-In Fahrspur
                const canopy = new THREE.Mesh(new THREE.BoxGeometry(7.2, 0.45, 11.5), new THREE.MeshStandardMaterial({ color: 0xb91c1c, roughness: 0.35 }));
                canopy.position.set(-3.2, 4.4, 0);
                canopy.castShadow = true;
                driveInGroup.add(canopy);

                // Goldene Kanten-Zierleiste um das Vordach (wie im Bild)
                const goldTrim = new THREE.Mesh(new THREE.BoxGeometry(7.4, 0.15, 11.7), new THREE.MeshStandardMaterial({ color: 0xfbbf24, metalness: 0.8, roughness: 0.2 }));
                goldTrim.position.set(-3.2, 4.6, 0);
                driveInGroup.add(goldTrim);

                // Schräge V-Stützen aus Chrom
                const chromeMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.95, roughness: 0.1 });
                const pillar1 = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 4.4, 16), chromeMat);
                pillar1.position.set(-5.6, 2.2, -4.2);
                pillar1.rotation.z = -0.15;
                driveInGroup.add(pillar1);

                const pillar2 = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 4.4, 16), chromeMat);
                pillar2.position.set(-5.6, 2.2, 4.2);
                pillar2.rotation.z = -0.15;
                driveInGroup.add(pillar2);

                // Beleuchtetes "DRIVE-IN" Retro-Dachschild (Canvas-Textur)
                const signMat = new THREE.MeshStandardMaterial({ map: this.getDriveInFasciaTexture(), roughness: 0.3 });
                const roofSign = new THREE.Mesh(new THREE.BoxGeometry(0.2, 1.6, 5.2), signMat);
                roofSign.position.set(-3.2, 5.4, 0);
                driveInGroup.add(roofSign);

                // Roter Retro-Klassiker unter dem Vordach geparkt (wie im Foto!)
                const carGroup = new THREE.Group();
                carGroup.position.set(-3.8, 0.4, 1.5);
                const carBody = new THREE.Mesh(new THREE.BoxGeometry(2.0, 0.65, 4.4), new THREE.MeshStandardMaterial({ color: 0xb91c1c, metalness: 0.4, roughness: 0.3 }));
                carBody.position.set(0, 0.45, 0);
                carGroup.add(carBody);
                const carTop = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.5, 2.0), new THREE.MeshStandardMaterial({ color: 0xfef3c7, roughness: 0.4 }));
                carTop.position.set(0, 0.95, -0.3);
                carGroup.add(carTop);
                // Räder
                [-0.9, 0.9].forEach(wx => {
                    [-1.3, 1.3].forEach(wz => {
                        const wheel = new THREE.Mesh(new THREE.CylinderGeometry(0.3, 0.3, 0.2, 12), new THREE.MeshStandardMaterial({ color: 0x18181b }));
                        wheel.rotation.z = Math.PI / 2;
                        wheel.position.set(wx, 0.3, wz);
                        carGroup.add(wheel);
                    });
                });
                driveInGroup.add(carGroup);

                // =========================================================
                // DER SCHWEBENDE GOLD-TRESOR MIT MÜNZWIRBEL (HIGHLIGHT!)
                // =========================================================
                const vaultGroup = new THREE.Group();
                vaultGroup.position.set(-3.2, 6.4, 0);
                vaultGroup.rotation.y = Math.PI / 5;

                // Goldener Tresor-Würfel mit Nieten & Relief-Textur
                const vaultMat = new THREE.MeshStandardMaterial({ map: this.getMysteryVaultTexture(), metalness: 0.85, roughness: 0.2 });
                const vaultBody = new THREE.Mesh(new THREE.BoxGeometry(2.4, 2.2, 2.4), vaultMat);
                vaultBody.castShadow = true;
                vaultGroup.add(vaultBody);

                // Geöffnete Tresor-Pforte mit goldenem Glanz
                const door = new THREE.Mesh(new THREE.BoxGeometry(2.2, 2.0, 0.3), vaultMat);
                door.position.set(1.3, 0, 1.4);
                door.rotation.y = -Math.PI / 3;
                vaultGroup.add(door);

                // Intensiver goldener Lichtschein aus dem Inneren
                const vaultLight = new THREE.PointLight(0xfbbf24, 2.5, 10);
                vaultLight.position.set(0, 0, 0.5);
                vaultGroup.add(vaultLight);

                // Halo aus 12 schwebenden Goldmünzen rund um den Tresor
                const coinMat = new THREE.MeshStandardMaterial({ color: 0xfef08a, metalness: 0.9, roughness: 0.15 });
                for (let c = 0; c < 12; c++) {
                    const ang = (c / 12) * Math.PI * 2;
                    const r = 2.4 + (c % 3) * 0.4;
                    const coin = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.24, 0.08, 12), coinMat);
                    coin.position.set(Math.cos(ang) * r, (c % 4) * 0.3 - 0.45, Math.sin(ang) * r);
                    coin.rotation.x = Math.random() * Math.PI;
                    coin.rotation.y = Math.random() * Math.PI;
                    vaultGroup.add(coin);
                }
                driveInGroup.add(vaultGroup);
                group.add(driveInGroup);

            } else if (isRadioTicker) {
                // =============================================================
                // 2. RADIO TICKER 📻 — HOOD FM FUNKHAUS & SENDE-MAST (BILD 2)
                // =============================================================
                const radioGroup = new THREE.Group();
                radioGroup.position.set(3.5, 0, 0);

                // Ziegel-Gebäude (2-geschossig)
                const studioBody = new THREE.Mesh(new THREE.BoxGeometry(8.5, 5.2, 9.5), new THREE.MeshStandardMaterial({ color: 0x431407, roughness: 0.85 }));
                studioBody.position.set(1.5, 2.8, 0);
                studioBody.castShadow = true;
                radioGroup.add(studioBody);

                // Riesiges HOOD FM Graffiti-Wandbild auf der Front (Canvas-Textur!)
                const graffitiMat = new THREE.MeshStandardMaterial({ map: this.getHoodFmGraffitiTexture(), roughness: 0.8 });
                const graffitiWall = new THREE.Mesh(new THREE.BoxGeometry(0.1, 4.4, 8.8), graffitiMat);
                graffitiWall.position.set(-2.8, 2.8, 0);
                radioGroup.add(graffitiWall);

                // Obere Studiofenster mit warmem ON-AIR Schein
                const winOnAir = new THREE.Mesh(new THREE.BoxGeometry(0.15, 1.4, 4.5), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
                winOnAir.position.set(-2.82, 4.2, 1.5);
                radioGroup.add(winOnAir);

                // Dach-Ausrüstung: 2 weiße Satellitenschüsseln
                const dishMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.5, roughness: 0.3 });
                [ -1.8, 1.8 ].forEach(dz => {
                    const dish = new THREE.Mesh(new THREE.ConeGeometry(1.2, 0.45, 16), dishMat);
                    dish.position.set(0.5, 5.9, dz);
                    dish.rotation.x = -0.7;
                    dish.rotation.y = 0.3;
                    radioGroup.add(dish);

                    const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 1.0, 8), dishMat);
                    pole.position.set(0.5, 5.5, dz);
                    radioGroup.add(pole);
                });

                // =========================================================
                // DER ROT-WEISS GESTREIFTE GITTER-SENDEMAST (18m HOCH!)
                // =========================================================
                const mastMatR = new THREE.MeshStandardMaterial({ color: 0xdc2626, metalness: 0.6, roughness: 0.3 });
                const mastMatW = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.6, roughness: 0.3 });
                const towerX = 4.2;
                const towerZ = -3.2;

                // 8 rot-weiße Gitter-Segmente
                for (let s = 0; s < 8; s++) {
                    const h = 2.1;
                    const wBot = 1.6 - s * 0.12;
                    const wTop = 1.48 - s * 0.12;
                    const mat = (s % 2 === 0) ? mastMatR : mastMatW;

                    // 4 Eck-Pfeiler des Gittermasts
                    [-wBot / 2, wBot / 2].forEach(ox => {
                        [-wBot / 2, wBot / 2].forEach(oz => {
                            const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.07, h, 8), mat);
                            leg.position.set(towerX + ox, 0.4 + s * h + h / 2, towerZ + oz);
                            radioGroup.add(leg);
                        });
                    });

                    // Horizontale Verbindungsstreben
                    const cross = new THREE.Mesh(new THREE.BoxGeometry(wBot, 0.08, wBot), mat);
                    cross.position.set(towerX, 0.4 + s * h + h, towerZ);
                    radioGroup.add(cross);
                }

                // Blinkendes rotes Hindernisfeuer auf der Mastspitze
                const beacon = new THREE.Mesh(new THREE.SphereGeometry(0.35, 12, 12), new THREE.MeshBasicMaterial({ color: 0xff0000 }));
                beacon.position.set(towerX, 17.5, towerZ);
                radioGroup.add(beacon);

                const beaconLight = new THREE.PointLight(0xff0000, 2.0, 15);
                beaconLight.position.set(towerX, 17.5, towerZ);
                radioGroup.add(beaconLight);

                // HOOD FM Übertragungswagen im Hof geparkt
                const van = new THREE.Mesh(new THREE.BoxGeometry(2.2, 1.4, 4.2), new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.5 }));
                van.position.set(-2.2, 0.9, -3.2);
                radioGroup.add(van);
                const vanDecal = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.6, 2.6), new THREE.MeshBasicMaterial({ color: 0xf97316 }));
                vanDecal.position.set(-3.32, 0.9, -3.2);
                radioGroup.add(vanDecal);

                group.add(radioGroup);

            } else if (tileType === 'go_to_jail' || (!isHandschuhfach && !isRadioTicker && tileType === 'radar')) {
                // =============================================================
                // 3. RADARFALLE 📸 — POLISCAN-BLITZERSÄULE (BILD 3)
                // =============================================================
                const radarGroup = new THREE.Group();
                // Direkt am Straßenrand platziert, sodass der Fahrer direkt daran vorbeifährt!
                radarGroup.position.set(-4.8, 0, 0.5);

                // Betonsockel
                const baseGeo = new THREE.BoxGeometry(1.6, 0.4, 1.6);
                const baseMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.9 });
                const baseMesh = new THREE.Mesh(baseGeo, baseMat);
                baseMesh.position.set(0, 0.4, 0);
                baseMesh.receiveShadow = true;
                radarGroup.add(baseMesh);

                // Anthrazitfarbene Metallsäule (Matte Slate / Graphite)
                const pillarMat = new THREE.MeshStandardMaterial({ color: 0x1e242c, metalness: 0.45, roughness: 0.35 });
                const pillar = new THREE.Mesh(new THREE.CylinderGeometry(0.55, 0.55, 4.6, 24), pillarMat);
                pillar.position.set(0, 2.7, 0);
                pillar.castShadow = true;
                radarGroup.add(pillar);

                // 4 horizontale Glas-Sensorringe (Laser, Kamera 1, Rotlicht-Blitz, Kamera 2)
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x020617, roughness: 0.1, metalness: 0.9 });
                const flashRingMat = new THREE.MeshBasicMaterial({ color: 0xb91c1c });

                [
                    { y: 4.3, isFlash: false },
                    { y: 3.6, isFlash: false },
                    { y: 2.9, isFlash: true }, // Rotlicht-Blitzglas
                    { y: 2.2, isFlash: false },
                ].forEach(ring => {
                    const cut = new THREE.Mesh(new THREE.CylinderGeometry(0.56, 0.56, 0.45, 24), ring.isFlash ? flashRingMat : glassMat);
                    cut.position.set(0, ring.y, 0);
                    radarGroup.add(cut);

                    // Innere Linse
                    const lens = new THREE.Mesh(new THREE.SphereGeometry(0.25, 12, 12), new THREE.MeshStandardMaterial({ color: 0x090d16, roughness: 0.1 }));
                    lens.position.set(0.35, ring.y, 0);
                    radarGroup.add(lens);
                });

                // Rote Status-LED
                const diode = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 8), new THREE.MeshBasicMaterial({ color: 0xef4444 }));
                diode.position.set(0.56, 2.9, 0);
                radarGroup.add(diode);

                group.add(radarGroup);

            } else if (tileType === 'tax') {
                // =============================================================
                // 4. MAUTSTELLE 🛑 — AUTOBAHN TOLL PLAZA (BILD 4)
                // =============================================================
                const tollGroup = new THREE.Group();
                tollGroup.position.set(1.5, 0, 0);

                // Großes Stahl-Überdachungsportal (überbrückt die Fahrspur)
                const canopyMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.6, roughness: 0.4 });
                const tollCanopy = new THREE.Mesh(new THREE.BoxGeometry(11.5, 0.7, 13.0), canopyMat);
                tollCanopy.position.set(-2.0, 5.2, 0);
                tollCanopy.castShadow = true;
                tollGroup.add(tollCanopy);

                // Stahl-Pfeiler für das Dach
                [-6.5, 2.5].forEach(px => {
                    [-5.5, 5.5].forEach(pz => {
                        const col = new THREE.Mesh(new THREE.BoxGeometry(0.5, 5.2, 0.5), canopyMat);
                        col.position.set(px, 2.6, pz);
                        tollGroup.add(col);
                    });
                });

                // Digitales LED-Spuranzeigen-Schild (Canvas-Textur mit roten Kreuzen & grünen Pfeilen!)
                const signMat = new THREE.MeshStandardMaterial({ map: this.getTollPlazaSignTexture(), roughness: 0.2 });
                const matrixSign = new THREE.Mesh(new THREE.BoxGeometry(0.2, 1.4, 11.0), signMat);
                matrixSign.position.set(-6.55, 4.4, 0);
                tollGroup.add(matrixSign);

                // Mauthäuschen (Toll Booth) mit Fenster
                const booth = new THREE.Mesh(new THREE.BoxGeometry(2.4, 3.4, 3.2), new THREE.MeshStandardMaterial({ color: 0x475569 }));
                booth.position.set(0.5, 1.9, 0);
                tollGroup.add(booth);
                const boothWin = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.2, 2.0), new THREE.MeshBasicMaterial({ color: 0x38bdf8 }));
                boothWin.position.set(-0.75, 2.2, 0);
                tollGroup.add(boothWin);

                // Gelb-schwarz schraffierter Beton-Prallschutz (Hazard Chevron Stripes)
                const hazardMat = new THREE.MeshStandardMaterial({ map: this.getHazardStripeTexture(), roughness: 0.7 });
                const crashBarrier = new THREE.Mesh(new THREE.BoxGeometry(1.8, 1.0, 4.5), hazardMat);
                crashBarrier.position.set(-2.5, 0.7, -3.5);
                tollGroup.add(crashBarrier);

                // Rot-weiß gestreifte Schranke über der Fahrspur
                const barrierBoom = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.08, 5.8, 8), new THREE.MeshStandardMaterial({ color: 0xdc2626 }));
                barrierBoom.position.set(-4.5, 1.2, 0);
                barrierBoom.rotation.x = Math.PI / 2;
                tollGroup.add(barrierBoom);

                group.add(tollGroup);

            } else if (tileType === 'jail') {
                // =============================================================
                // 5. WERKSTATT / GEFÄNGNIS 🔧 — KFZ-MEISTERBETRIEB (BILD 5)
                // =============================================================
                const shopGroup = new THREE.Group();
                shopGroup.position.set(3.5, 0, 0);

                // Backstein-Werkstatthalle mit Rolltoren
                const shopBody = new THREE.Mesh(new THREE.BoxGeometry(9.5, 5.4, 11.5), new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.9 }));
                shopBody.position.set(1.5, 2.8, 0);
                shopBody.castShadow = true;
                shopGroup.add(shopBody);

                // Großes Fassadenschild "KFZ-Meisterbetrieb - Reparatur aller Marken"
                const signMat = new THREE.MeshStandardMaterial({ map: this.getWorkshopSignTexture(), roughness: 0.5 });
                const shopSign = new THREE.Mesh(new THREE.BoxGeometry(0.15, 1.6, 9.2), signMat);
                shopSign.position.set(-3.3, 4.8, 0);
                shopGroup.add(shopSign);

                // 2 geöffnete Werkstatt-Tore (Blick in die helle Werkstatt)
                [-2.8, 2.8].forEach(bz => {
                    const bayCut = new THREE.Mesh(new THREE.BoxGeometry(0.18, 3.4, 3.8), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
                    bayCut.position.set(-3.28, 1.9, bz);
                    shopGroup.add(bayCut);
                });

                // =========================================================
                // BLAUE 2-SÄULEN-HEBEBÜHNE MIT AUFGEBOCKTEM WAGEN (WIE IM FOTO!)
                // =========================================================
                const liftMat = new THREE.MeshStandardMaterial({ color: 0x0284c7, metalness: 0.6, roughness: 0.4 });
                const post1 = new THREE.Mesh(new THREE.BoxGeometry(0.35, 4.0, 0.35), liftMat);
                post1.position.set(-4.2, 2.0, -1.8);
                shopGroup.add(post1);

                const post2 = new THREE.Mesh(new THREE.BoxGeometry(0.35, 4.0, 0.35), liftMat);
                post2.position.set(-4.2, 2.0, 1.8);
                shopGroup.add(post2);

                // Aufgebocktes silbernes Fahrzeug auf 1.8m Höhe
                const carOnLift = new THREE.Mesh(new THREE.BoxGeometry(2.0, 1.1, 4.2), new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.6, roughness: 0.3 }));
                carOnLift.position.set(-4.2, 2.5, 0);
                carOnLift.castShadow = true;
                shopGroup.add(carOnLift);

                // Reifenstapel im Hof
                for (let t = 0; t < 4; t++) {
                    const tire = new THREE.Mesh(new THREE.CylinderGeometry(0.48, 0.48, 0.28, 12), new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.9 }));
                    tire.position.set(-5.6, 0.45 + t * 0.28, 3.8);
                    shopGroup.add(tire);
                }

                // Blaue und rote Ölfässer
                const drumB = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 1.0, 12), new THREE.MeshStandardMaterial({ color: 0x1d4ed8 }));
                drumB.position.set(-5.4, 0.8, -3.8);
                shopGroup.add(drumB);
                const drumR = new THREE.Mesh(new THREE.CylinderGeometry(0.35, 0.35, 1.0, 12), new THREE.MeshStandardMaterial({ color: 0xdc2626 }));
                drumR.position.set(-4.8, 0.8, -4.2);
                shopGroup.add(drumR);

                group.add(shopGroup);

            } else if (tileType === 'parking') {
                // =============================================================
                // 6. RASTPLATZ ☕ — KÜSTEN-AUSSICHTSPUNKT (BILD 6)
                // =============================================================
                const overlookGroup = new THREE.Group();
                overlookGroup.position.set(2.5, 0, 0);

                // Paved Stone Aussichtsterrasse mit Meerblick
                const terrace = new THREE.Mesh(new THREE.BoxGeometry(13.0, 0.45, 14.0), new THREE.MeshStandardMaterial({ color: 0xd4d4d8, roughness: 0.85 }));
                terrace.position.set(-1.0, 0.35, 0);
                terrace.receiveShadow = true;
                overlookGroup.add(terrace);

                // Rustikale Naturstein-Pfeiler mit Holz-Doppelgeländer (wie im Foto!)
                const stoneMat = new THREE.MeshStandardMaterial({ color: 0x78716c, roughness: 0.9 });
                const woodRailMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.7 });

                [-6.5, 0, 5.5].forEach(pz => {
                    const stonePillar = new THREE.Mesh(new THREE.BoxGeometry(0.8, 1.3, 0.8), stoneMat);
                    stonePillar.position.set(-5.5, 0.95, pz);
                    overlookGroup.add(stonePillar);
                });

                // Horizontale Holz-Geländerstangen
                const rail1 = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.15, 12.0), woodRailMat);
                rail1.position.set(-5.5, 1.25, 0);
                overlookGroup.add(rail1);
                const rail2 = new THREE.Mesh(new THREE.BoxGeometry(0.12, 0.15, 12.0), woodRailMat);
                rail2.position.set(-5.5, 0.85, 0);
                overlookGroup.add(rail2);

                // Poliertes Edelstahl-Aussichtsfernrohr (Coin-Telescope auf Sockel)
                const scopeGroup = new THREE.Group();
                scopeGroup.position.set(-5.2, 1.1, -1.8);
                const scopePedestal = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.16, 1.1, 12), new THREE.MeshStandardMaterial({ color: 0x64748b, metalness: 0.8 }));
                scopeGroup.add(scopePedestal);
                const scopeBarrel = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.07, 0.9, 12), new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.95, roughness: 0.1 }));
                scopeBarrel.rotation.z = -0.35;
                scopeBarrel.position.set(-0.15, 0.65, 0);
                scopeGroup.add(scopeBarrel);
                overlookGroup.add(scopeGroup);

                // Parkbänke mit Holzlattung & Eisenfüßen
                [-3.5, 2.5].forEach(bz => {
                    const bench = new THREE.Mesh(new THREE.BoxGeometry(0.7, 0.8, 2.2), woodRailMat);
                    bench.position.set(-3.5, 0.7, bz);
                    overlookGroup.add(bench);
                });

                // Picknick-Tisch mit Sitzbänken
                const picnicTable = new THREE.Mesh(new THREE.BoxGeometry(1.6, 0.85, 2.6), woodRailMat);
                picnicTable.position.set(1.5, 0.7, -2.5);
                overlookGroup.add(picnicTable);

                // Panorama-Informationstafel mit Karte (Canvas-Textur!)
                const panoMat = new THREE.MeshStandardMaterial({ map: this.getPanoramatafelTexture(), roughness: 0.4 });
                const panoBoard = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.0, 1.8), panoMat);
                panoBoard.position.set(-3.2, 1.3, 0);
                panoBoard.rotation.z = -0.25;
                overlookGroup.add(panoBoard);

                // Blaues "P Aussichtspunkt" Verkehrsschild an der Zufahrt
                const signPole = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.4, 8), new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.8 }));
                signPole.position.set(-6.8, 1.5, 5.8);
                overlookGroup.add(signPole);
                const pSign = new THREE.Mesh(new THREE.BoxGeometry(0.05, 0.9, 0.9), new THREE.MeshStandardMaterial({ map: this.getViewpointSignTexture() }));
                pSign.position.set(-6.8, 2.2, 5.8);
                overlookGroup.add(pSign);

                // Mediterrane Pinien / Zypressen für natürlichen Schatten
                [ { x: 3.5, z: 3.5, h: 5.5 }, { x: 4.0, z: -4.5, h: 6.2 } ].forEach(tree => {
                    const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.35, tree.h * 0.4, 8), new THREE.MeshStandardMaterial({ color: 0x451a03 }));
                    trunk.position.set(tree.x, tree.h * 0.2, tree.z);
                    overlookGroup.add(trunk);

                    const crown = new THREE.Mesh(new THREE.SphereGeometry(tree.h * 0.35, 8, 8), new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.85 }));
                    crown.position.set(tree.x, tree.h * 0.65, tree.z);
                    crown.scale.set(1.4, 0.8, 1.4);
                    overlookGroup.add(crown);
                });

                group.add(overlookGroup);

            } else if (tileType === 'start') {
                // =============================================================
                // 7. START & ZIEL 🏁 — GRAND-PRIX ZIELBOGEN
                // =============================================================
                const archMat = new THREE.MeshStandardMaterial({ color: 0xdc2626, metalness: 0.7, roughness: 0.3 });
                const p1 = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.25, 8.0, 16), archMat);
                p1.position.set(-6.5, 4.0, -4.8);
                group.add(p1);
                const p2 = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.25, 8.0, 16), archMat);
                p2.position.set(-6.5, 4.0, 4.8);
                group.add(p2);
                const beam = new THREE.Mesh(new THREE.BoxGeometry(0.7, 1.1, 10.2), archMat);
                beam.position.set(-6.5, 8.0, 0);
                group.add(beam);

                const banner = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.5, 8.8), new THREE.MeshBasicMaterial({ color: 0xf8fafc }));
                banner.position.set(-6.5, 6.7, 0);
                group.add(banner);

            } else {
                // Fallback / Generischer Landmark-Pfosten
                const tower = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.35, 4.5, 12), new THREE.MeshStandardMaterial({ color: 0x1e293b }));
                tower.position.set(-3.5, 2.25, 0);
                group.add(tower);
            }
        }

        // ---------------------------------------------------------------------
        // 8. WEICHE FAHRT ENTLANG DES SPLINES
        // ---------------------------------------------------------------------
        driveToTile(fromIndex, toIndex, onComplete) {
            this.isDriving = true;
            this.audio.playEngine();

            // Während der Fahrt: Blick exakt geradeaus auf die Straße!
            this.targetYaw = 0.0;
            this.targetPitch = 0.0;

            const steps = (toIndex >= fromIndex) ? (toIndex - fromIndex) : (40 - fromIndex + toIndex);
            const totalDuration = Math.max(1400, steps * 280);
            const startTime = performance.now();

            const stepLoop = (now) => {
                const elapsed = now - startTime;
                const progress = Math.min(elapsed / totalDuration, 1);

                // Sanfte Ease-In-Out Beschleunigung
                const eased = progress < 0.5
                    ? 4 * progress * progress * progress
                    : 1 - Math.pow(-2 * progress + 2, 3) / 2;

                const currentU = (fromIndex / 40.0 + (steps / 40.0) * eased) % 1.0;
                const pos = this.trackCurve.getPointAt(currentU);
                const tangent = this.trackCurve.getTangentAt(currentU).normalize();
                const normal = new THREE.Vector3(-tangent.z, 0, tangent.x).normalize();

                // Auto fährt flüssig auf der rechten Fahrspur (x = +3.2m)
                const carPos = pos.clone().add(normal.clone().multiplyScalar(3.2));
                this.cockpitRig.position.copy(carPos);
                this.cockpitRig.rotation.y = Math.atan2(-tangent.x, -tangent.z);

                if (progress < 1) {
                    requestAnimationFrame(stepLoop);
                } else {
                    this.isDriving = false;
                    // Am Ziel sanft nach rechts zum Grundstück blicken
                    this.targetYaw = -0.7;
                    this.targetPitch = -0.05;
                    if (onComplete) onComplete();
                }
            };

            requestAnimationFrame(stepLoop);
        }

        // ---------------------------------------------------------------------
        // 9. INTERAKTION: BLICKSTEUERUNG
        // ---------------------------------------------------------------------
        bindEvents() {
            const c = this.container;

            c.addEventListener('pointerdown', (e) => {
                this.isDragging = true;
                this.prevTouch = { x: e.clientX, y: e.clientY };
            });

            window.addEventListener('pointermove', (e) => {
                if (!this.isDragging) return;
                const dx = e.clientX - this.prevTouch.x;
                const dy = e.clientY - this.prevTouch.y;

                this.targetYaw -= dx * 0.006;
                this.targetPitch = Math.max(-0.4, Math.min(0.4, this.targetPitch - dy * 0.005));

                this.prevTouch = { x: e.clientX, y: e.clientY };
            });

            window.addEventListener('pointerup', () => {
                this.isDragging = false;
            });

            this.ui.btnLookPlot.addEventListener('click', () => {
                this.targetYaw = -0.85; // Zum Grundstück schauen
                this.targetPitch = -0.05;
            });

            this.ui.btnLookRoad.addEventListener('click', () => {
                this.targetYaw = 0.0; // Geradeaus nach vorn schauen
                this.targetPitch = 0.0;
            });

            this.ui.btnRoll.addEventListener('click', () => this.actionRoll());
            this.ui.btnBuy.addEventListener('click', () => this.actionBuy());
            this.ui.btnPass.addEventListener('click', () => this.actionPass());
            this.ui.btnEndTurn.addEventListener('click', () => this.actionEndTurn());
            this.ui.btnBail.addEventListener('click', () => this.actionBail());

            document.getElementById('sheet-close').addEventListener('click', () => this.closePropertySheet());
            this.ui.sheetBtnBuild.addEventListener('click', () => this.actionBuild());
            this.ui.sheetBtnMortgage.addEventListener('click', () => this.actionMortgage());

            document.getElementById('btn-audio-toggle').addEventListener('click', (e) => {
                this.audio.enabled = !this.audio.enabled;
                e.currentTarget.textContent = this.audio.enabled ? '🔊' : '🔇';
            });

            document.getElementById('btn-leave').addEventListener('click', () => {
                window.location.href = '../index.php';
            });

            const btnRestart = document.getElementById('btn-restart-game');
            if (btnRestart) {
                btnRestart.addEventListener('click', () => window.location.reload());
            }
        }

        // ---------------------------------------------------------------------
        // 10. API-SYNC & NAVI-UPDATE
        // ---------------------------------------------------------------------
        async apiPost(action, payload = {}) {
            payload.room_code = this.roomCode;
            try {
                const res = await fetch(`api.php?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Player-Token': this.playerToken || '',
                    },
                    body: JSON.stringify(payload),
                });
                return await res.json();
            } catch (e) {
                return { success: false, message: 'Netzwerkfehler' };
            }
        }

        async loadRoomState() {
            try {
                const res = await fetch(`api.php?action=state&room_code=${this.roomCode}`, {
                    headers: { 'X-Player-Token': this.playerToken || '' }
                });
                const data = await res.json();
                if (data.success) {
                    this.applyState(data.state, data.me);
                }
            } catch (e) {}
        }

        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => {
                if (!this.isDriving) {
                    this.loadRoomState();
                }
            }, 1500);
        }

        applyState(newState, me) {
            const oldState = this.state;
            this.state = newState;
            if (me) {
                this.myPlayerId = me.id;
                this.isHost = !!me.is_host;
                this.ui.cashBadge.textContent = `${me.cash} $`;

                if (this.carHoodMat && me.color) {
                    this.carHoodMat.color.set(me.color);
                }
            }

            this.ui.roomBadge.textContent = this.roomCode;

            if (newState.log && newState.log.length > 0) {
                this.ui.tickerText.textContent = newState.log[newState.log.length - 1].text;
            }

            // Grundstücke 3D aktualisieren
            if (newState.tiles) {
                newState.tiles.forEach(t => {
                    const owner = newState.players.find(p => p.id === t.owner);
                    this.renderPlotState(t, owner ? owner.color : null, t.mortgaged);
                });
            }

            // Fahrt animieren
            const activePlayer = newState.players[newState.current_player_index];
            if (activePlayer) {
                if (oldState && !this.isDriving) {
                    const oldActive = oldState.players.find(p => p.id === activePlayer.id);
                    if (oldActive && oldActive.position !== activePlayer.position) {
                        this.driveToTile(oldActive.position, activePlayer.position, () => {
                            this.updateNaviScreen(newState, activePlayer);
                        });
                    } else {
                        const tp = this.trackPoints[activePlayer.position || 0];
                        if (tp) {
                            const carPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(3.2));
                            this.cockpitRig.position.copy(carPos);
                            this.cockpitRig.rotation.y = tp.angle;
                        }
                        this.updateNaviScreen(newState, activePlayer);
                    }
                } else if (!this.isDriving) {
                    const tp = this.trackPoints[activePlayer.position || 0];
                    if (tp) {
                        const carPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(3.2));
                        this.cockpitRig.position.copy(carPos);
                        this.cockpitRig.rotation.y = tp.angle;
                    }
                    this.updateNaviScreen(newState, activePlayer);
                }
            }

            if (newState.last_dice) {
                document.getElementById('die-1').textContent = newState.last_dice[0];
                document.getElementById('die-2').textContent = newState.last_dice[1];
            }

            if (newState.status === 'finished' && newState.winner_id) {
                const winner = newState.players.find(p => p.id === newState.winner_id);
                if (winner) {
                    this.ui.winnerText.textContent = `👑 ${winner.name} gewinnt die Hood!`;
                    this.ui.winnerModal.classList.add('open');
                }
            }

            if (newState.status === 'lobby') {
                this.renderLobbyModal(newState);
            } else {
                this.ui.lobbyModal.classList.remove('open');
            }
        }

        updateNaviScreen(state, activePlayer) {
            const tileIdx = activePlayer.position;
            const tile = state.tiles[tileIdx];
            if (!tile) return;

            this.selectedTileIndex = tileIdx;
            this.ui.naviName.textContent = tile.name;

            const isPurchasable = (tile.type === 'street' || tile.type === 'beach' || tile.type === 'club');

            if (this.ui.statsPurchasable && this.ui.eventBox) {
                if (isPurchasable) {
                    this.ui.statsPurchasable.style.display = 'grid';
                    this.ui.eventBox.style.display = 'none';

                    const owner = state.players.find(p => p.id === tile.owner);
                    this.ui.naviOwner.textContent = owner ? owner.name : 'Frei';
                    this.ui.naviPrice.textContent = `${tile.price || 0} $`;
                    this.ui.naviRent.textContent = `${tile.price ? Math.round(tile.price * 0.1) : 0} $`;
                } else {
                    this.ui.statsPurchasable.style.display = 'none';
                    this.ui.eventBox.style.display = 'flex';

                    let icon = '📍';
                    let eventTitle = tile.name;
                    let desc = tile.desc || '';

                    if (tile.type === 'card') {
                        const isRadio = (tile.deck === 'radio' || [2, 17, 33].includes(tileIdx));
                        icon = isRadio ? '📻' : '🗃️';
                        if (state.last_card && state.last_card.text) {
                            eventTitle = isRadio ? 'Live aus dem Radio Ticker' : 'Aus dem Handschuhfach gezogen';
                            desc = state.last_card.text;
                        } else {
                            eventTitle = tile.name;
                            desc = isRadio ? 'Zufällige Live-Nachricht aus der Hood.' : 'Greif ins Fach: Glück oder Bußgeld?';
                        }
                    } else if (tile.type === 'tax') {
                        icon = '🛑';
                        eventTitle = tile.name;
                        desc = 'Fällige Abgabe: 200 $ an die Stadtkasse entrichtet.';
                    } else if (tile.type === 'go_to_jail') {
                        icon = '📸';
                        eventTitle = 'Radarfalle ausgelöst!';
                        desc = 'Zu schnell gefahren! Wagen wird in die Werkstatt geschleppt.';
                    } else if (tile.type === 'jail') {
                        icon = '🔧';
                        eventTitle = tile.name;
                        desc = activePlayer.in_jail
                            ? `Wagen auf der Hebebühne (${activePlayer.jail_turns || 1}/3). 50 $ Kaution zahlen.`
                            : 'Nur zu Besuch. Freie Weiterfahrt!';
                    } else if (tile.type === 'parking') {
                        icon = '☕';
                        eventTitle = tile.name;
                        desc = 'Kostenlose Rast mit Meerblick. Keine Gebühren fällig.';
                    } else if (tile.type === 'start') {
                        icon = '🏁';
                        eventTitle = tile.name;
                        desc = 'Ziehe 200 $ Prämie ein beim Passieren oder Landen!';
                    }

                    this.ui.eventIcon.textContent = icon;
                    this.ui.eventTitle.textContent = eventTitle;
                    this.ui.eventDesc.textContent = desc;
                }
            } else {
                const owner = state.players.find(p => p.id === tile.owner);
                this.ui.naviOwner.textContent = owner ? owner.name : 'Frei';
                this.ui.naviPrice.textContent = `${tile.price || 0} $`;
                this.ui.naviRent.textContent = `${tile.price ? Math.round(tile.price * 0.1) : 0} $`;
            }

            const groupNames = {
                docks: 'Docks & Industrie',
                suburb: 'Vorstadt & Diners',
                arts: 'Künstlerviertel',
                promenade: 'Flaniermeile',
                downtown: 'Downtown',
                marina: 'Yachthafen',
                heights: 'Panorama-Höhen',
                millionaires: 'Millionaires’ Hill',
            };
            this.ui.naviZoneTag.textContent = groupNames[tile.group] || tile.type.toUpperCase();

            if (activePlayer) {
                this.ui.cashBadge.textContent = `${activePlayer.cash} $`;
                if (this.carHoodMat && activePlayer.color) {
                    this.carHoodMat.color.set(activePlayer.color);
                }
            }

            const isHotSeat = !!state.is_hotseat;
            const isMyTurn = isHotSeat ? (!activePlayer.is_bot) : (activePlayer.id === this.myPlayerId);

            if (isHotSeat) {
                this.ui.roomBadge.textContent = `🛋️ ${activePlayer.name}`;
            }

            this.ui.btnRoll.style.display = 'none';
            this.ui.btnGroupBuy.style.display = 'none';
            this.ui.btnEndTurn.style.display = 'none';
            this.ui.btnBail.style.display = 'none';

            if (!isMyTurn) return;

            if (state.phase === 'ROLL') {
                this.ui.btnRoll.style.display = 'flex';
                if (activePlayer.in_jail && activePlayer.cash >= 50) {
                    this.ui.btnBail.style.display = 'flex';
                }
            } else if (state.phase === 'BUY_DECISION') {
                this.ui.btnGroupBuy.style.display = 'flex';
                this.ui.btnBuy.textContent = `🏡 KAUFEN (${tile.price} $)`;
            } else if (state.phase === 'TURN_END') {
                this.ui.btnEndTurn.style.display = 'flex';
            }
        }

        // ---------------------------------------------------------------------
        // 11. AKTIONEN
        // ---------------------------------------------------------------------
        async actionRoll() {
            this.audio.playRoll();
            const res = await this.apiPost('roll');
            if (res.success) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            }
        }

        async actionBuy() {
            this.audio.playCash();
            const res = await this.apiPost('buy');
            if (res.success) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            }
        }

        async actionPass() {
            const res = await this.apiPost('pass');
            if (res.success) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            }
        }

        async actionEndTurn() {
            const res = await this.apiPost('end_turn');
            if (res.success) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            }
        }

        async actionBail() {
            this.audio.playCash();
            const res = await this.apiPost('pay_bail');
            if (res.success) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            }
        }

        async actionBuild() {
            if (this.selectedTileIndex === null) return;
            this.audio.playBuild();
            const res = await this.apiPost('build', { tile_index: this.selectedTileIndex });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            } else {
                alert(res.message || 'Ausbau nicht möglich');
            }
        }

        async actionMortgage() {
            if (this.selectedTileIndex === null) return;
            this.audio.playCash();
            const res = await this.apiPost('mortgage', { tile_index: this.selectedTileIndex });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            } else {
                alert(res.message || 'Aktion nicht möglich');
            }
        }

        openPropertySheet(tileIndex) {
            this.selectedTileIndex = tileIndex;
            const tile = this.state ? this.state.tiles[tileIndex] : null;
            if (!tile) return;

            this.ui.sheetTitle.textContent = tile.name;
            this.ui.sheetPrice.textContent = `${tile.price} $`;

            const owner = this.state.players.find(p => p.id === tile.owner);
            this.ui.sheetOwner.textContent = owner ? `Besitzer: ${owner.name}` : 'Frei / Zu verkaufen';

            const isMine = (tile.owner === this.myPlayerId);
            this.ui.sheetBtnBuild.style.display = (isMine && tile.type === 'street') ? 'block' : 'none';
            this.ui.sheetBtnMortgage.style.display = isMine ? 'block' : 'none';
            this.ui.sheetBtnMortgage.textContent = tile.mortgaged ? 'Hypothek ablösen' : 'Mit Hypothek beleihen';

            this.ui.sheetBackdrop.classList.add('open');
        }

        closePropertySheet() {
            this.ui.sheetBackdrop.classList.remove('open');
        }

        // ---------------------------------------------------------------------
        // 12. LOBBY
        // ---------------------------------------------------------------------
        showLobbyModal(isCreating) {
            this.ui.lobbyModal.classList.add('open');
            const formCreate = document.getElementById('lobby-create-form');
            formCreate.style.display = isCreating ? 'block' : 'none';

            let chosenMode = 'hotseat';
            const tabHotSeat = document.getElementById('mode-tab-hotseat');
            const tabOnline = document.getElementById('mode-tab-online');

            if (tabHotSeat && tabOnline) {
                tabHotSeat.addEventListener('click', () => {
                    chosenMode = 'hotseat';
                    tabHotSeat.classList.remove('hood-btn-secondary');
                    tabOnline.classList.add('hood-btn-secondary');
                });
                tabOnline.addEventListener('click', () => {
                    chosenMode = 'online';
                    tabOnline.classList.remove('hood-btn-secondary');
                    tabHotSeat.classList.add('hood-btn-secondary');
                });
            }

            const colorSwatches = document.querySelectorAll('.hood-color-swatch');
            colorSwatches.forEach(swatch => {
                swatch.addEventListener('click', (e) => {
                    colorSwatches.forEach(s => s.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            const vehicleCards = document.querySelectorAll('.hood-vehicle-card');
            vehicleCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    vehicleCards.forEach(c => c.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            document.getElementById('btn-create-room').addEventListener('click', async () => {
                const name = document.getElementById('input-player-name').value.trim() || 'Driver';
                const pin = document.getElementById('input-pin').value.trim();
                const selColor = document.querySelector('.hood-color-swatch.selected');
                const color = selColor ? selColor.dataset.color : '#ef4444';
                const selVeh = document.querySelector('.hood-vehicle-card.selected');
                const vehicle = selVeh ? selVeh.dataset.vehicle : 'sportscar';

                const res = await fetch('api.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        player_name: name,
                        pin: pin,
                        color: color,
                        vehicle: vehicle,
                        is_hotseat: (chosenMode === 'hotseat')
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.roomCode = data.room_code;
                    this.playerToken = data.player_token;
                    localStorage.setItem('quarxl_hood_token_' + this.roomCode, this.playerToken);
                    window.history.replaceState({}, '', `?room=${this.roomCode}`);
                    this.applyState(data.state, data.state.players[0]);
                    this.startPolling();
                } else {
                    alert(data.message || 'Konnte Raum nicht anlegen');
                }
            });

            const btnAddLocal = document.getElementById('btn-add-local');
            if (btnAddLocal) {
                btnAddLocal.addEventListener('click', async () => {
                    const inputLocal = document.getElementById('input-local-name');
                    const name = inputLocal ? inputLocal.value.trim() : 'Spieler';
                    if (!name) return;
                    const res = await this.apiPost('add_local', { player_name: name });
                    if (res.success) {
                        if (inputLocal) inputLocal.value = '';
                        this.applyState(res.state, res.state.players[0]);
                    }
                });
            }

            document.getElementById('btn-add-bot').addEventListener('click', async () => {
                const res = await this.apiPost('add_bot');
                if (res.success) {
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                }
            });

            document.getElementById('btn-start-game').addEventListener('click', async () => {
                this.audio.playHorn();
                const res = await this.apiPost('start');
                if (res.success) {
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                } else {
                    alert(res.message || 'Start fehlgeschlagen');
                }
            });
        }

        renderLobbyModal(state) {
            this.ui.lobbyModal.classList.add('open');
            document.getElementById('lobby-create-form').style.display = 'none';
            document.getElementById('lobby-waiting-room').style.display = 'block';

            const isHotSeat = !!state.is_hotseat;
            document.getElementById('lobby-room-code').textContent = isHotSeat ? '🛋️ HOT-SEAT' : this.roomCode;
            document.getElementById('lobby-room-info').textContent = isHotSeat
                ? 'Spielt gemeinsam an diesem Bildschirm. Füge Mitspieler oder KI-Fahrer hinzu.'
                : 'Teile den Code mit Freunden auf ihren eigenen Geräten.';

            const hotseatBox = document.getElementById('hotseat-add-box');
            if (hotseatBox) {
                hotseatBox.style.display = (isHotSeat && this.isHost) ? 'block' : 'none';
            }

            const list = document.getElementById('lobby-players-list');
            list.innerHTML = '';
            state.players.forEach(p => {
                const li = document.createElement('li');
                li.style.display = 'flex';
                li.style.alignItems = 'center';
                li.style.gap = '0.5rem';
                li.style.padding = '0.5rem 0';
                li.innerHTML = `
                    <div style="width: 14px; height: 14px; border-radius: 50%; background: ${p.color}; box-shadow: 0 0 6px ${p.color};"></div>
                    <strong>${p.name}</strong>
                    <span style="color: var(--hood-muted); font-size: 0.8rem;">(${p.is_bot ? 'KI' : 'Lokal'})</span>
                `;
                list.appendChild(li);
            });

            document.getElementById('btn-add-bot').style.display = this.isHost ? 'block' : 'none';
            document.getElementById('btn-start-game').style.display = this.isHost ? 'block' : 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.hoodGame = new HoodGame();
        window.hoodGame.init();
    });
})();
