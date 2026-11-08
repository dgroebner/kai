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

                // Elegante geschwungene Straßenlaterne alle 2 Stationen
                if (i % 2 === 0) {
                    const lampPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(7.5));
                    this.addCurvedStreetLamp(lampPos, tp.angle);
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

            // 5. Beifahrersitz rechts (gibt echtes Cockpit-Gefühl, blockiert aber nicht den Blick!)
            const seatGroup = new THREE.Group();
            seatGroup.position.set(0.75, 0.55, 0.1);

            const seatBaseGeo = new THREE.BoxGeometry(0.7, 0.35, 0.7);
            const seatMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.8 });
            const seatBase = new THREE.Mesh(seatBaseGeo, seatMat);
            seatGroup.add(seatBase);

            const seatBackGeo = new THREE.BoxGeometry(0.65, 0.9, 0.2);
            const seatBack = new THREE.Mesh(seatBackGeo, seatMat);
            seatBack.position.set(0, 0.5, 0.3);
            seatBack.rotation.x = -0.15;
            seatGroup.add(seatBack);

            const headrestGeo = new THREE.BoxGeometry(0.35, 0.25, 0.15);
            const headrest = new THREE.Mesh(headrestGeo, seatMat);
            headrest.position.set(0, 1.05, 0.38);
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
                this.renderSpecialPlot(group, tileType, tileName);
            }
        }

        // --- A. BARS & CLUBS (Bauruine bei Leerstand -> Aktiver VIP-Nightclub bei Besitz) ---
        renderClubPlot(group, ownerColor, isMortgaged, tileName) {
            const isOwned = !!ownerColor;

            if (!isOwned) {
                // 1. DIE CLUB-BAURUINE (Unfertiges Industriegelände mit Warnabsperrungen & Baugerüst)
                const ruinMat = new THREE.MeshStandardMaterial({ color: 0x475569, roughness: 0.95 });
                const brickMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.9 });
                const rebarMat = new THREE.MeshStandardMaterial({ color: 0xb45309, roughness: 0.8 }); // Rostiger Bewehrungsstahl

                // Rissiges Beton-Fundament
                const baseGeo = new THREE.BoxGeometry(22, 0.3, 19);
                const base = new THREE.Mesh(baseGeo, ruinMat);
                base.position.set(0, 0.15, 0);
                base.receiveShadow = true;
                group.add(base);

                // Drei abgebrochene, unfertige Beton- & Ziegelwand-Fragmente
                const wall1 = new THREE.Mesh(new THREE.BoxGeometry(8, 3.8, 1.2), ruinMat);
                wall1.position.set(3, 1.9, -4);
                wall1.castShadow = true;
                group.add(wall1);

                const wall2 = new THREE.Mesh(new THREE.BoxGeometry(1.2, 2.5, 7), brickMat);
                wall2.position.set(-4, 1.25, 1);
                wall2.castShadow = true;
                group.add(wall2);

                const wall3 = new THREE.Mesh(new THREE.BoxGeometry(5, 1.8, 1.0), ruinMat);
                wall3.position.set(2, 0.9, 5);
                group.add(wall3);

                // Rostige Bewehrungsstäbe (Rebar), die oben aus den Wänden ragen
                for (let r = 0; r < 5; r++) {
                    const rebarGeo = new THREE.CylinderGeometry(0.04, 0.04, 1.3, 6);
                    const rebar = new THREE.Mesh(rebarGeo, rebarMat);
                    rebar.position.set(1 + r * 1.2, 4.2, -4);
                    rebar.rotation.z = (Math.random() - 0.5) * 0.25;
                    group.add(rebar);
                }

                // Vernagelte Tür mit Kreuzbrettern
                const boardGeo = new THREE.BoxGeometry(0.12, 0.35, 2.4);
                const woodMat = new THREE.MeshStandardMaterial({ color: 0x713f12, roughness: 0.9 });
                const b1 = new THREE.Mesh(boardGeo, woodMat);
                b1.position.set(-3.4, 1.4, 1);
                b1.rotation.y = 0.5;
                group.add(b1);
                const b2 = new THREE.Mesh(boardGeo, woodMat);
                b2.position.set(-3.4, 1.4, 1);
                b2.rotation.y = -0.5;
                group.add(b2);

                // Gelb-schwarze Baustellen-Absperrschranke
                const barrierGeo = new THREE.BoxGeometry(0.2, 0.6, 4.2);
                const barrierMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.7 });
                const barrier = new THREE.Mesh(barrierGeo, barrierMat);
                barrier.position.set(-6.5, 0.6, 0);
                group.add(barrier);

                // Verwittertes Bauschild "BAURUINE • ZU VERKAUFEN"
                const signPost = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 2.8), woodMat);
                signPost.position.set(-6.5, 1.4, 3.5);
                group.add(signPost);

                const signBoard = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.2, 2.6), new THREE.MeshStandardMaterial({ color: 0xca8a04 }));
                signBoard.position.set(-6.5, 2.2, 3.5);
                group.add(signBoard);

            } else {
                // 2. DER WIEDERHERGESTELLTE, AKTIVE VIP-NIGHTCLUB / LOUNGE
                const hex = ownerColor;
                const facadeMat = new THREE.MeshStandardMaterial({ color: 0x09090b, roughness: 0.35, metalness: 0.5 });
                const neonMat = new THREE.MeshBasicMaterial({ color: hex });
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.8, transparent: true, opacity: 0.65 });

                // Edler dunkler Vorplatz
                const plazaGeo = new THREE.BoxGeometry(22, 0.35, 19);
                const plazaMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.7 });
                const plaza = new THREE.Mesh(plazaGeo, plazaMat);
                plaza.position.set(0, 0.18, 0);
                plaza.receiveShadow = true;
                group.add(plaza);

                // Club-Hauptgebäude in luxuriöser kubischer Architektur
                const clubBody = new THREE.Mesh(new THREE.BoxGeometry(12, 6.2, 12), facadeMat);
                clubBody.position.set(2, 3.2, 0);
                clubBody.castShadow = true;
                group.add(clubBody);

                // Leuchtende Neon-Lichtbänder an den Kanten in Spielerfarbe!
                const neonRoof = new THREE.Mesh(new THREE.BoxGeometry(12.4, 0.25, 12.4), neonMat);
                neonRoof.position.set(2, 6.35, 0);
                group.add(neonRoof);

                const neonVertical = new THREE.Mesh(new THREE.BoxGeometry(0.25, 6.2, 0.25), neonMat);
                neonVertical.position.set(-4.1, 3.2, 6.1);
                group.add(neonVertical);

                // Großes leuchtendes 3D-Neonschild über dem Portal
                const neonSign = new THREE.Mesh(new THREE.BoxGeometry(0.3, 1.4, 5.5), neonMat);
                neonSign.position.set(-4.2, 4.8, 0);
                group.add(neonSign);

                // Roter VIP-Teppichläufer vom Gehweg bis zum Eingang
                const carpetMat = new THREE.MeshStandardMaterial({ color: 0xbe123c, roughness: 0.8 });
                const carpet = new THREE.Mesh(new THREE.BoxGeometry(6, 0.05, 2.2), carpetMat);
                carpet.position.set(-6.5, 0.38, 0);
                group.add(carpet);

                // Goldene Absperrständer mit Kordel (Velvet Rope)
                const brassMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, metalness: 0.9, roughness: 0.2 });
                [-1.2, 1.2].forEach(oz => {
                    [-4.5, -7.5].forEach(ox => {
                        const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.04, 1.0, 8), brassMat);
                        pole.position.set(ox, 0.88, oz);
                        group.add(pole);

                        const ball = new THREE.Mesh(new THREE.SphereGeometry(0.08, 8, 8), brassMat);
                        ball.position.set(ox, 1.4, oz);
                        group.add(ball);
                    });
                });

                // Rooftop Sky-Lounge mit Glasbalustrade & Skybeam-Suchscheinwerfern
                const skybeamGeo = new THREE.CylinderGeometry(0.2, 3.5, 45, 16);
                const skybeamMat = new THREE.MeshBasicMaterial({ color: hex, transparent: true, opacity: 0.35 });
                const beam1 = new THREE.Mesh(skybeamGeo, skybeamMat);
                beam1.position.set(5, 28, 4);
                group.add(beam1);
                const beam2 = new THREE.Mesh(skybeamGeo, skybeamMat);
                beam2.position.set(5, 28, -4);
                group.add(beam2);
            }
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

        // --- D. SPEZIELLE FELDER (Start, Werkstatt, Rastplatz, Radar, Karten) ---
        renderSpecialPlot(group, tileType, tileName) {
            const plotMat = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.8 });
            const plot = new THREE.Mesh(new THREE.BoxGeometry(20, 0.35, 18), plotMat);
            plot.position.set(0, 0.18, 0);
            group.add(plot);

            if (tileType === 'start') {
                // Grand-Prix Start- & Ziel-Torbogen
                const archMat = new THREE.MeshStandardMaterial({ color: 0xef4444, metalness: 0.6 });
                const p1 = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.2, 7), archMat);
                p1.position.set(-6.5, 3.5, -4);
                group.add(p1);
                const p2 = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.2, 7), archMat);
                p2.position.set(-6.5, 3.5, 4);
                group.add(p2);
                const beam = new THREE.Mesh(new THREE.BoxGeometry(0.4, 0.8, 8.5), archMat);
                beam.position.set(-6.5, 7.2, 0);
                group.add(beam);
            } else if (tileType === 'jail') {
                // Kfz-Verwahrstelle & Hebebühne
                const garage = new THREE.Mesh(new THREE.BoxGeometry(9, 5, 10), new THREE.MeshStandardMaterial({ color: 0x64748b }));
                garage.position.set(1.5, 2.5, 0);
                group.add(garage);
                const lift = new THREE.Mesh(new THREE.BoxGeometry(2, 2.5, 4), new THREE.MeshStandardMaterial({ color: 0xeab308 }));
                lift.position.set(-5, 1.25, 0);
                group.add(lift);
            } else if (tileType === 'parking') {
                // Panorama-Aussichtspunkt mit Picknickbank
                const shelter = new THREE.Mesh(new THREE.ConeGeometry(3.5, 2.2, 6), new THREE.MeshStandardMaterial({ color: 0x15803d }));
                shelter.position.set(0, 3.5, 0);
                group.add(shelter);
            } else {
                // Funkmast oder Infotafel
                const pole = new THREE.Mesh(new THREE.CylinderGeometry(0.1, 0.1, 6), new THREE.MeshStandardMaterial({ color: 0x94a3b8 }));
                pole.position.set(-4, 3, 0);
                group.add(pole);
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

            const owner = state.players.find(p => p.id === tile.owner);
            this.ui.naviOwner.textContent = owner ? owner.name : 'Frei';

            this.ui.naviPrice.textContent = `${tile.price || 0} $`;
            this.ui.naviRent.textContent = `${tile.price ? Math.round(tile.price * 0.1) : 0} $`;

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
