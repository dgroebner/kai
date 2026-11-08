/**
 * UNDER THE HOOD 🏎️ — 3D First-Person Cockpit & Sunny Coastal Circuit
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 1. WEBAUDIO SOUND ENGINE (Synthetisch — keine externen MP3s nötig!)
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
    // 2. HAUPTKLASSE: HOOD GAME (COCKPIT EGO-PERSPEKTIVE & GESCHWUNGENE STRECKE)
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
            this.carMeshes = {};
            this.isDriving = false;

            // Spline-Rundkurs Daten
            this.trackCurve = null;
            this.trackPoints = []; // 40 Wegpunkte

            // Cockpit Rig
            this.cockpitRig = null;
            this.steeringWheel = null;
            this.carHoodMat = null;
            this.carHoodMesh = null;
            this.cockpitYaw = -0.75; // Standard beim Stehen: Blick nach schräg rechts zum Grundstück
            this.cockpitPitch = 0.0;
            this.targetYaw = -0.75;
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
            this.buildRealisticCircuit();
            this.buildCockpit();
            this.bindEvents();

            if (this.roomCode) {
                this.loadRoomState();
                this.startPolling();
            } else {
                this.showLobbyModal(true);
            }
        }

        // ---------------------------------------------------------------------
        // 3. THREE.JS SZENE: STRAHLENDER, SONNIGER TAG AM MEER
        // ---------------------------------------------------------------------
        setupThree() {
            const w = this.container.clientWidth;
            const h = this.container.clientHeight;

            this.scene = new THREE.Scene();
            // Strahlend blauer Sommerhimmel
            this.scene.background = new THREE.Color(0x7dd3fc);
            this.scene.fog = new THREE.FogExp2(0xbfe0f7, 0.0018);

            this.camera = new THREE.PerspectiveCamera(65, w / h, 0.1, 1400);

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
            this.renderer.setSize(w, h);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            this.renderer.shadowMap.enabled = true;
            this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            this.container.appendChild(this.renderer.domElement);

            // 1. Sonne: Kräftiges warmes Sonnenlicht
            const sun = new THREE.DirectionalLight(0xfffbeb, 1.8);
            sun.position.set(160, 260, 140);
            sun.castShadow = true;
            sun.shadow.mapSize.width = 2048;
            sun.shadow.mapSize.height = 2048;
            sun.shadow.camera.near = 10;
            sun.shadow.camera.far = 800;
            const d = 240;
            sun.shadow.camera.left = -d;
            sun.shadow.camera.right = d;
            sun.shadow.camera.top = d;
            sun.shadow.camera.bottom = -d;
            sun.shadow.bias = -0.0003;
            this.scene.add(sun);

            // 2. Himmels- und Bodenlicht (Blauer Himmel + grüner Rasen-Bounce)
            const hemi = new THREE.HemisphereLight(0x38bdf8, 0x4ade80, 0.75);
            this.scene.add(hemi);

            const ambient = new THREE.AmbientLight(0xe0f2fe, 0.65);
            this.scene.add(ambient);

            // 3. Glitzernder türkiser Ozean
            const oceanGeo = new THREE.PlaneGeometry(2400, 2400);
            const oceanMat = new THREE.MeshStandardMaterial({
                color: 0x0284c7,
                roughness: 0.15,
                metalness: 0.35,
            });
            const ocean = new THREE.Mesh(oceanGeo, oceanMat);
            ocean.rotation.x = -Math.PI / 2;
            ocean.position.y = -2;
            this.scene.add(ocean);

            // 4. Saftig grüne Küsteninsel
            const islandGeo = new THREE.PlaneGeometry(800, 800);
            const islandMat = new THREE.MeshStandardMaterial({
                color: 0x22c55e, // Schönes sattes Wiesengrün
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

                // Sanfte Kamera-Interpolation
                this.cockpitYaw = THREE.MathUtils.lerp(this.cockpitYaw, this.targetYaw, 0.08);
                this.cockpitPitch = THREE.MathUtils.lerp(this.cockpitPitch, this.targetPitch, 0.08);

                if (this.camera) {
                    this.camera.rotation.set(this.cockpitPitch, this.cockpitYaw, 0, 'YXZ');
                }

                if (this.steeringWheel && this.isDriving) {
                    this.steeringWheel.rotation.z = Math.sin(Date.now() * 0.005) * 0.3;
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
        // 4. GESCHWUNGENER KÜSTEN-RUNDKURS (SPLINE OHNE HARTE 90°-KNICKE)
        // ---------------------------------------------------------------------
        initSplineTrack() {
            // Kontrollpunkte für einen organisch geschwungenen Küsten-Rundkurs (ca. 400m x 350m)
            const controlPoints = [
                new THREE.Vector3(0, 0, 160),      // 0: Start & Ziel
                new THREE.Vector3(80, 0, 155),     // Docks
                new THREE.Vector3(155, 0, 120),    // North Beach
                new THREE.Vector3(195, 0, 50),     // Vorstadt
                new THREE.Vector3(205, 0, -40),    // Künstlerviertel
                new THREE.Vector3(170, 0, -125),   // Palm Bay
                new THREE.Vector3(95, 0, -175),    // Flaniermeile
                new THREE.Vector3(0, 0, -190),     // Downtown Skyline (Nordseite)
                new THREE.Vector3(-95, 0, -180),   // Marina
                new THREE.Vector3(-165, 0, -130),  // Sunset Bay
                new THREE.Vector3(-205, 0, -45),   // Panorama-Höhen
                new THREE.Vector3(-195, 0, 50),    // Ocean Pier
                new THREE.Vector3(-150, 0, 125),   // Millionaires’ Hill
                new THREE.Vector3(-75, 0, 160),    // Anfahrt Start
            ];

            this.trackCurve = new THREE.CatmullRomCurve3(controlPoints, true, 'centripetal', 0.5);

            // 40 gleichmäßig verteilte Streckenabschnitte berechnen
            this.trackPoints = [];
            for (let i = 0; i < 40; i++) {
                const u = i / 40.0;
                const pos = this.trackCurve.getPointAt(u);
                const tangent = this.trackCurve.getTangentAt(u).normalize();

                // Normalenvektor nach rechts: N = (-tangent.z, 0, tangent.x)
                const normal = new THREE.Vector3(-tangent.z, 0, tangent.x).normalize();

                // Rotationswinkel um Y: Auto-Ausrichtung vorwärts entlang Tangente
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

        buildRealisticCircuit() {
            const asphaltMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.85 });
            const curbMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.6 });
            const sidewalkMat = new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.8 });
            const laneMat = new THREE.MeshBasicMaterial({ color: 0xfacc15 });

            for (let i = 0; i < 40; i++) {
                const tp = this.trackPoints[i];
                const nextTp = this.trackPoints[(i + 1) % 40];
                const segLength = tp.pos.distanceTo(nextTp.pos);

                const tileGroup = new THREE.Group();
                tileGroup.position.copy(tp.pos);
                tileGroup.rotation.y = tp.angle;

                // 1. Breite 2-spurige Straße (14m breit)
                const roadGeo = new THREE.BoxGeometry(14, 0.4, segLength + 0.5);
                const road = new THREE.Mesh(roadGeo, asphaltMat);
                road.position.set(0, 0.2, -(segLength / 2));
                road.receiveShadow = true;
                road.userData = { tileIndex: i };
                tileGroup.add(road);
                this.tileMeshes[i] = road;

                // Gelbe gestrichelte Mittellinie
                const laneGeo = new THREE.BoxGeometry(0.35, 0.42, segLength * 0.5);
                const lane = new THREE.Mesh(laneGeo, laneMat);
                lane.position.set(0, 0.21, -(segLength / 2));
                tileGroup.add(lane);

                // Weißer Bordstein & Gehweg rechts
                const curbGeo = new THREE.BoxGeometry(0.5, 0.55, segLength + 0.5);
                const curb = new THREE.Mesh(curbGeo, curbMat);
                curb.position.set(7.25, 0.27, -(segLength / 2));
                curb.castShadow = true;
                tileGroup.add(curb);

                const walkGeo = new THREE.BoxGeometry(5.5, 0.5, segLength + 0.5);
                const walk = new THREE.Mesh(walkGeo, sidewalkMat);
                walk.position.set(10.25, 0.25, -(segLength / 2));
                walk.receiveShadow = true;
                tileGroup.add(walk);

                // Straßenlaterne am Gehweg
                this.addStreetLamp(tileGroup, 9, -(segLength / 2));

                // 2. Das Grundstück rechts neben dem Gehweg (Sicherer Abstand: x = 24m)
                // Dadurch schneidet das Auto in Kurven NIEMALS durch Schilder oder Häuser!
                const plotGroup = new THREE.Group();
                plotGroup.position.set(24, 0, -(segLength / 2));
                tileGroup.add(plotGroup);
                this.tilePlotGroups[i] = plotGroup;

                // Initial als sonnige Wildnis (Stufe 0) rendern
                this.renderPlotState(i, 0, null, false);

                this.scene.add(tileGroup);
            }
        }

        addStreetLamp(parent, x, z) {
            const poleGeo = new THREE.CylinderGeometry(0.12, 0.15, 6, 8);
            const poleMat = new THREE.MeshStandardMaterial({ color: 0x475569, metalness: 0.6 });
            const pole = new THREE.Mesh(poleGeo, poleMat);
            pole.position.set(x, 3, z);
            pole.castShadow = true;
            parent.add(pole);

            const lampGeo = new THREE.SphereGeometry(0.4, 8, 8);
            const lampMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
            const lamp = new THREE.Mesh(lampGeo, lampMat);
            lamp.position.set(x - 0.5, 5.8, z);
            parent.add(lamp);
        }

        // ---------------------------------------------------------------------
        // 5. DAS 3D-COCKPIT (KORREKTE AUSRICHTUNG: BLICK NACH VORN ÜBER DIE HAUBE!)
        // ---------------------------------------------------------------------
        buildCockpit() {
            this.cockpitRig = new THREE.Group();

            // 1. Motorhaube vorn (in Fahrtrichtung -Z!)
            const hoodGeo = new THREE.BoxGeometry(3.6, 0.55, 3.8);
            this.carHoodMat = new THREE.MeshStandardMaterial({
                color: 0xef4444,
                roughness: 0.2,
                metalness: 0.6,
            });
            this.carHoodMesh = new THREE.Mesh(hoodGeo, this.carHoodMat);
            this.carHoodMesh.position.set(0, 0.65, -2.6);
            this.carHoodMesh.castShadow = true;
            this.cockpitRig.add(this.carHoodMesh);

            // 2. Armaturenbrett (vor der Scheibe)
            const dashMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.85 });
            const dashGeo = new THREE.BoxGeometry(3.8, 0.75, 1.4);
            const dash = new THREE.Mesh(dashGeo, dashMat);
            dash.position.set(0, 0.85, -0.7);
            this.cockpitRig.add(dash);

            // 3. Windschutzscheiben-A-Säulen
            const pillarMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.7 });
            const pillarGeo = new THREE.BoxGeometry(0.18, 2.2, 0.25);

            const leftPillar = new THREE.Mesh(pillarGeo, pillarMat);
            leftPillar.position.set(-1.8, 1.6, -0.6);
            leftPillar.rotation.z = -0.3;
            leftPillar.rotation.x = 0.3;
            this.cockpitRig.add(leftPillar);

            const rightPillar = new THREE.Mesh(pillarGeo, pillarMat);
            rightPillar.position.set(1.8, 1.6, -0.6);
            rightPillar.rotation.z = 0.3;
            rightPillar.rotation.x = 0.3;
            this.cockpitRig.add(rightPillar);

            // Dach-Querträger mit Rückspiegel
            const roofGeo = new THREE.BoxGeometry(3.8, 0.2, 0.5);
            const roof = new THREE.Mesh(roofGeo, pillarMat);
            roof.position.set(0, 2.45, -0.2);
            this.cockpitRig.add(roof);

            const mirrorGeo = new THREE.BoxGeometry(0.9, 0.28, 0.08);
            const mirrorMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.1, metalness: 0.9 });
            const mirror = new THREE.Mesh(mirrorGeo, mirrorMat);
            mirror.position.set(0, 2.25, -0.3);
            this.cockpitRig.add(mirror);

            // 4. Sport-Lenkrad auf der Fahrerseite (Links, z = -0.35)
            const wheelGroup = new THREE.Group();
            wheelGroup.position.set(-0.7, 0.95, -0.35);
            wheelGroup.rotation.x = 0.35;

            const ringGeo = new THREE.TorusGeometry(0.42, 0.045, 8, 24);
            const wheelMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.7 });
            const ring = new THREE.Mesh(ringGeo, wheelMat);
            wheelGroup.add(ring);

            const hubGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.06, 12);
            hubGeo.rotateX(Math.PI / 2);
            const hub = new THREE.Mesh(hubGeo, wheelMat);
            wheelGroup.add(hub);

            this.cockpitRig.add(wheelGroup);
            this.steeringWheel = wheelGroup;

            // 5. Die Kamera auf Augenhöhe des Fahrers (Links, Blick nach -Z)
            this.camera.position.set(-0.7, 1.45, 0.2);
            this.cockpitRig.add(this.camera);

            this.scene.add(this.cockpitRig);
        }

        // ---------------------------------------------------------------------
        // 6. VISUELLE GRUNDSTÜCKS-EVOLUTION IM SONNENLICHT
        // ---------------------------------------------------------------------
        renderPlotState(tileIndex, level, ownerColor = null, isMortgaged = false) {
            const group = this.tilePlotGroups[tileIndex];
            if (!group) return;

            while (group.children.length > 0) {
                group.remove(group.children[0]);
            }

            // Grundstücks-Boden: 22m x 20m
            const plotMat = new THREE.MeshStandardMaterial({
                color: level === 0 ? 0x854d0e : 0x16a34a, // Trockene Wildnis vs. sattes Grün
                roughness: 0.85,
            });
            const plotGeo = new THREE.BoxGeometry(22, 0.4, 20);
            const plot = new THREE.Mesh(plotGeo, plotMat);
            plot.position.set(0, 0.2, 0);
            plot.receiveShadow = true;
            group.add(plot);

            const hexColor = ownerColor || '#3b82f6';
            const playerMat = new THREE.MeshStandardMaterial({ color: hexColor, roughness: 0.5 });

            // STUFE 0: WILDNIS (Sonniges Brachland mit Steinen & großem Holzschild)
            if (level === 0) {
                const rockGeo = new THREE.DodecahedronGeometry(1.2, 0);
                const rockMat = new THREE.MeshStandardMaterial({ color: 0x71717a, roughness: 0.9 });
                const r1 = new THREE.Mesh(rockGeo, rockMat);
                r1.position.set(-4, 0.8, -3);
                r1.castShadow = true;
                group.add(r1);

                const r2 = new THREE.Mesh(rockGeo, rockMat);
                r2.position.set(3, 0.7, 4);
                r2.scale.set(0.8, 0.8, 0.8);
                group.add(r2);

                // Großes Holzschild "ZU VERKAUFEN"
                const postGeo = new THREE.CylinderGeometry(0.12, 0.12, 3.5);
                const woodMat = new THREE.MeshStandardMaterial({ color: 0x92400e, roughness: 0.8 });
                const p1 = new THREE.Mesh(postGeo, woodMat);
                p1.position.set(-10, 1.75, -2);
                group.add(p1);

                const p2 = new THREE.Mesh(postGeo, woodMat);
                p2.position.set(-10, 1.75, 2);
                group.add(p2);

                const signGeo = new THREE.BoxGeometry(0.15, 1.8, 4.4);
                const signMat = new THREE.MeshStandardMaterial({ color: 0xfef08a });
                const sign = new THREE.Mesh(signGeo, signMat);
                sign.position.set(-10, 2.7, 0);
                sign.castShadow = true;
                group.add(sign);
                return;
            }

            // STUFE 1: ERSCHLOSSEN (Zaun in Spielerfarbe, gemäht & beflaggt)
            if (level >= 1) {
                const fenceGeo = new THREE.BoxGeometry(0.2, 1.3, 19);
                const fence = new THREE.Mesh(fenceGeo, playerMat);
                fence.position.set(-10.5, 0.9, 0);
                fence.castShadow = true;
                group.add(fence);

                const pillarGeo = new THREE.BoxGeometry(0.6, 2.2, 0.6);
                const pillarMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.6 });
                const pLeft = new THREE.Mesh(pillarGeo, pillarMat);
                pLeft.position.set(-10.5, 1.1, -3);
                group.add(pLeft);

                const pRight = new THREE.Mesh(pillarGeo, pillarMat);
                pRight.position.set(-10.5, 1.1, 3);
                group.add(pRight);

                const flagPoleGeo = new THREE.CylinderGeometry(0.08, 0.08, 6);
                const pole = new THREE.Mesh(flagPoleGeo, pillarMat);
                pole.position.set(-9.5, 3, -4);
                group.add(pole);

                const flagGeo = new THREE.BoxGeometry(0.05, 1.2, 1.8);
                const flag = new THREE.Mesh(flagGeo, playerMat);
                flag.position.set(-9.5, 5.2, -4.9);
                group.add(flag);
            }

            // STUFE 2–4: 1 BIS 3 MODERNE VILLEN
            if (level >= 2 && level <= 4) {
                const count = level - 1;
                const offsetsZ = count === 1 ? [0] : count === 2 ? [-5, 5] : [-6, 0, 6];

                offsetsZ.forEach(oz => {
                    const houseGroup = new THREE.Group();
                    houseGroup.position.set(0, 0, oz);

                    const bodyGeo = new THREE.BoxGeometry(10, 4.8, 5.5);
                    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.4 });
                    const body = new THREE.Mesh(bodyGeo, bodyMat);
                    body.position.set(0, 2.6, 0);
                    body.castShadow = true;
                    body.receiveShadow = true;
                    houseGroup.add(body);

                    const roofGeo = new THREE.ConeGeometry(5.2, 2.2, 4);
                    const roof = new THREE.Mesh(roofGeo, playerMat);
                    roof.position.set(0, 5.8, 0);
                    roof.rotation.y = Math.PI / 4;
                    roof.castShadow = true;
                    houseGroup.add(roof);

                    const winGeo = new THREE.BoxGeometry(0.1, 1.8, 2.8);
                    const winMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
                    const win = new THREE.Mesh(winGeo, winMat);
                    win.position.set(-5.05, 2.8, 0);
                    houseGroup.add(win);

                    group.add(houseGroup);
                });
            }

            // STUFE 5: LUXUS-HOTEL MIT POOL & PALMEN
            if (level === 5) {
                const hotelGroup = new THREE.Group();

                const mainGeo = new THREE.BoxGeometry(14, 8.5, 14);
                const mainMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.3, metalness: 0.2 });
                const main = new THREE.Mesh(mainGeo, mainMat);
                main.position.set(2, 4.45, 0);
                main.castShadow = true;
                hotelGroup.add(main);

                const glassGeo = new THREE.BoxGeometry(0.2, 6.5, 10);
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.8 });
                const glass = new THREE.Mesh(glassGeo, glassMat);
                glass.position.set(-5.1, 4.5, 0);
                hotelGroup.add(glass);

                // Swimming Pool
                const poolGeo = new THREE.BoxGeometry(6, 0.3, 8);
                const poolMat = new THREE.MeshBasicMaterial({ color: 0x06b6d4 });
                const pool = new THREE.Mesh(poolGeo, poolMat);
                pool.position.set(-8, 0.45, 0);
                hotelGroup.add(pool);

                this.addPalmTree(hotelGroup, -8, -5);
                this.addPalmTree(hotelGroup, -8, 5);

                group.add(hotelGroup);
            }
        }

        addPalmTree(parent, x, z) {
            const trunkGeo = new THREE.CylinderGeometry(0.2, 0.35, 5, 8);
            const trunkMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.9 });
            const trunk = new THREE.Mesh(trunkGeo, trunkMat);
            trunk.position.set(x, 2.5, z);
            trunk.rotation.z = (Math.random() - 0.5) * 0.2;
            parent.add(trunk);

            const crownGeo = new THREE.SphereGeometry(2.2, 6, 6);
            const crownMat = new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.8 });
            const crown = new THREE.Mesh(crownGeo, crownMat);
            crown.position.set(x, 5.2, z);
            crown.scale.set(1.4, 0.5, 1.4);
            parent.add(crown);
        }

        // ---------------------------------------------------------------------
        // 7. WEICHE FAHRT ENTLANG DES SPLINES (BLICK NACH VORN)
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

                // Sanfte Beschleunigungs- und Bremskurve (Cubic Ease In-Out)
                const eased = progress < 0.5
                    ? 4 * progress * progress * progress
                    : 1 - Math.pow(-2 * progress + 2, 3) / 2;

                const currentU = (fromIndex / 40.0 + (steps / 40.0) * eased) % 1.0;
                const pos = this.trackCurve.getPointAt(currentU);
                const tangent = this.trackCurve.getTangentAt(currentU).normalize();
                const normal = new THREE.Vector3(-tangent.z, 0, tangent.x).normalize();

                // Auto fährt auf der rechten Fahrspur (x = +3.2m relativ zur Straßenmitte)
                const carPos = pos.clone().add(normal.clone().multiplyScalar(3.2));
                this.cockpitRig.position.copy(carPos);
                this.cockpitRig.rotation.y = Math.atan2(-tangent.x, -tangent.z);

                if (progress < 1) {
                    requestAnimationFrame(stepLoop);
                } else {
                    this.isDriving = false;
                    // Am Ziel sanft nach rechts zum Grundstück blicken
                    this.targetYaw = -0.75;
                    this.targetPitch = -0.05;
                    if (onComplete) onComplete();
                }
            };

            requestAnimationFrame(stepLoop);
        }

        // ---------------------------------------------------------------------
        // 8. INTERAKTION: BLICKSTEUERUNG IM COCKPIT
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

            // Quick-Blick Toggles
            this.ui.btnLookPlot.addEventListener('click', () => {
                this.targetYaw = -0.85; // Aus dem Beifahrerfenster aufs Grundstück schauen
                this.targetPitch = -0.05;
            });

            this.ui.btnLookRoad.addEventListener('click', () => {
                this.targetYaw = 0.0; // Geradeaus nach vorn durch die Scheibe blicken
                this.targetPitch = 0.0;
            });

            // Spiel-Buttons
            this.ui.btnRoll.addEventListener('click', () => this.actionRoll());
            this.ui.btnBuy.addEventListener('click', () => this.actionBuy());
            this.ui.btnPass.addEventListener('click', () => this.actionPass());
            this.ui.btnEndTurn.addEventListener('click', () => this.actionEndTurn());
            this.ui.btnBail.addEventListener('click', () => this.actionBail());

            // Sheet Buttons
            document.getElementById('sheet-close').addEventListener('click', () => this.closePropertySheet());
            this.ui.sheetBtnBuild.addEventListener('click', () => this.actionBuild());
            this.ui.sheetBtnMortgage.addEventListener('click', () => this.actionMortgage());

            // Audio Toggle
            document.getElementById('btn-audio-toggle').addEventListener('click', (e) => {
                this.audio.enabled = !this.audio.enabled;
                e.currentTarget.textContent = this.audio.enabled ? '🔊' : '🔇';
            });

            // Leave Button
            document.getElementById('btn-leave').addEventListener('click', () => {
                window.location.href = '../index.php';
            });

            // Restart Button
            const btnRestart = document.getElementById('btn-restart-game');
            if (btnRestart) {
                btnRestart.addEventListener('click', () => window.location.reload());
            }
        }

        // ---------------------------------------------------------------------
        // 9. API-SYNC & NAVI-UPDATE
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
                    this.renderPlotState(t.index, t.level, owner ? owner.color : null, t.mortgaged);
                });
            }

            // Fahrt des aktiven Spielers animieren
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

            // Würfelanzeige
            if (newState.last_dice) {
                document.getElementById('die-1').textContent = newState.last_dice[0];
                document.getElementById('die-2').textContent = newState.last_dice[1];
            }

            // Sieger-Bildschirm
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

        // Aktualisiert die Anzeige auf dem Mittel-Navi
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

            // Cockpit & Tacho immer an den aktuell aktiven Fahrer anpassen
            if (activePlayer) {
                this.ui.cashBadge.textContent = `${activePlayer.cash} $`;
                if (this.carHoodMat && activePlayer.color) {
                    this.carHoodMat.color.set(activePlayer.color);
                }
            }

            // Buttons steuern (Hot-Seat vs. Online)
            const isHotSeat = !!state.is_hotseat;
            const isMyTurn = isHotSeat ? (!activePlayer.is_bot) : (activePlayer.id === this.myPlayerId);

            if (isHotSeat) {
                this.ui.roomBadge.textContent = `🛋️ ${activePlayer.name}`;
            }

            this.ui.btnRoll.style.display = 'none';
            this.ui.btnGroupBuy.style.display = 'none';
            this.ui.btnEndTurn.style.display = 'none';
            this.ui.btnBail.style.display = 'none';

            if (!isMyTurn) {
                return;
            }

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
        // 10. AKTIONEN
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
        // 11. LOBBY
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

            // Lokalen Mitspieler hinzufügen (Hot-Seat)
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
