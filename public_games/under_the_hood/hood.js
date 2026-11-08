/**
 * UNDER THE HOOD 🏎️ — 3D First-Person Cockpit & Game Engine
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
                    this.playTone(180 + Math.random() * 80, 'triangle', 0.08, 0.15);
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
                osc.frequency.setValueAtTime(80, this.ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(240, this.ctx.currentTime + 0.5);
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
            this.playTone(987.77, 'sine', 0.12, 0.2); // B5
            setTimeout(() => this.playTone(1318.51, 'sine', 0.35, 0.25), 100); // E6
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
    // 2. HAUPTKLASSE: HOOD GAME (COCKPIT EGO-PERSPEKTIVE)
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
            this.carMeshes = {}; // playerId -> Mesh
            this.isDriving = false;

            // Cockpit Rig
            this.cockpitRig = null;
            this.steeringWheel = null;
            this.carHoodMesh = null;
            this.cockpitYaw = -0.55; // Standard: Leicht nach rechts zum Grundstück blicken
            this.cockpitPitch = 0.0;
            this.targetYaw = -0.55;
            this.targetPitch = 0.0;

            // Touch Drag für freies Umsehen im Cockpit
            this.isDragging = false;
            this.prevTouch = { x: 0, y: 0 };

            // Dom-Elemente
            this.ui = {
                roomBadge: document.getElementById('room-badge-code'),
                cashBadge: document.getElementById('cash-badge-amount'),
                tickerText: document.getElementById('ticker-text'),
                // Navi Display Elemente
                naviZoneTag: document.getElementById('navi-zone-tag'),
                naviName: document.getElementById('navi-name'),
                naviOwner: document.getElementById('navi-owner'),
                naviPrice: document.getElementById('navi-price'),
                naviRent: document.getElementById('navi-rent'),
                // Buttons
                btnRoll: document.getElementById('btn-roll'),
                btnGroupBuy: document.getElementById('btn-group-buy'),
                btnBuy: document.getElementById('btn-buy'),
                btnPass: document.getElementById('btn-pass'),
                btnEndTurn: document.getElementById('btn-end-turn'),
                btnBail: document.getElementById('btn-bail'),
                // Blick-Umschalter
                btnLookPlot: document.getElementById('btn-look-plot'),
                btnLookRoad: document.getElementById('btn-look-road'),
                // Modals
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
            this.currentSpeed = 0;
        }

        init() {
            const urlParams = new URLSearchParams(window.location.search);
            this.roomCode = (urlParams.get('room') || '').toUpperCase();
            this.playerToken = localStorage.getItem('quarxl_hood_token_' + this.roomCode) || null;

            this.setupThree();
            this.buildRealisticTrack();
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
        // 3. THREE.JS SZENE & BELEUCHTUNG
        // ---------------------------------------------------------------------
        setupThree() {
            const w = this.container.clientWidth;
            const h = this.container.clientHeight;

            this.scene = new THREE.Scene();
            this.scene.background = new THREE.Color(0x0c1222);
            this.scene.fog = new THREE.FogExp2(0x0c1222, 0.0035);

            // Weitwinkelkamera für Cockpit-Gefühl (65° FOV)
            this.camera = new THREE.PerspectiveCamera(65, w / h, 0.1, 1200);

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
            this.renderer.setSize(w, h);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            this.renderer.shadowMap.enabled = true;
            this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            this.container.appendChild(this.renderer.domElement);

            // Himmels- und Sonnenlicht
            const ambient = new THREE.AmbientLight(0x94a3b8, 0.9);
            this.scene.add(ambient);

            const sun = new THREE.DirectionalLight(0xffedd5, 1.4);
            sun.position.set(120, 180, 90);
            sun.castShadow = true;
            sun.shadow.mapSize.width = 2048;
            sun.shadow.mapSize.height = 2048;
            sun.shadow.camera.near = 10;
            sun.shadow.camera.far = 600;
            const d = 160;
            sun.shadow.camera.left = -d;
            sun.shadow.camera.right = d;
            sun.shadow.camera.top = d;
            sun.shadow.camera.bottom = -d;
            sun.shadow.bias = -0.0003;
            this.scene.add(sun);

            // Weiches Himmelslicht
            const hemi = new THREE.HemisphereLight(0x38bdf8, 0x1e293b, 0.5);
            this.scene.add(hemi);

            // Ozean rund um die Insel
            const oceanGeo = new THREE.PlaneGeometry(1600, 1600);
            const oceanMat = new THREE.MeshStandardMaterial({ color: 0x0369a1, roughness: 0.15, metalness: 0.2 });
            const ocean = new THREE.Mesh(oceanGeo, oceanMat);
            ocean.rotation.x = -Math.PI / 2;
            ocean.position.y = -2;
            this.scene.add(ocean);

            // Insel-Terrain
            const landGeo = new THREE.PlaneGeometry(600, 600);
            const landMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.9 });
            const land = new THREE.Mesh(landGeo, landMat);
            land.rotation.x = -Math.PI / 2;
            land.position.y = -0.1;
            land.receiveShadow = true;
            this.scene.add(land);

            // Render-Schleife
            const animate = () => {
                requestAnimationFrame(animate);

                // Weiche Kamera-Interpolation
                this.cockpitYaw = THREE.MathUtils.lerp(this.cockpitYaw, this.targetYaw, 0.08);
                this.cockpitPitch = THREE.MathUtils.lerp(this.cockpitPitch, this.targetPitch, 0.08);

                if (this.camera) {
                    this.camera.rotation.set(this.cockpitPitch, this.cockpitYaw, 0, 'YXZ');
                }

                // Lenkrad-Drehung bei Kurvenfahrt
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
        // 4. REALISTISCHE STRASSEN-STRECKE (40 FELDER Á 20 METER)
        // ---------------------------------------------------------------------
        getTileCoords(index) {
            // Große Insel: Jede Seite hat 10 Kacheln á 20m = 200m pro Seite
            const half = 100;
            const step = (half * 2) / 10; // 20m pro Kachel

            if (index >= 0 && index <= 10) {
                // Südseite (Blick nach Osten)
                return { x: -half + index * step, z: half, rotY: Math.PI / 2 };
            } else if (index > 10 && index <= 20) {
                // Ostseite (Blick nach Norden)
                const i = index - 10;
                return { x: half, z: half - i * step, rotY: Math.PI };
            } else if (index > 20 && index <= 30) {
                // Nordseite (Blick nach Westen)
                const i = index - 20;
                return { x: half - i * step, z: -half, rotY: -Math.PI / 2 };
            } else {
                // Westseite (Blick nach Süden)
                const i = index - 30;
                return { x: -half, z: -half + i * step, rotY: 0 };
            }
        }

        buildRealisticTrack() {
            const asphaltMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.9 });
            const curbMat = new THREE.MeshStandardMaterial({ color: 0xe4e4e7, roughness: 0.7 });
            const sidewalkMat = new THREE.MeshStandardMaterial({ color: 0x475569, roughness: 0.8 });
            const laneMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });

            for (let i = 0; i < 40; i++) {
                const coords = this.getTileCoords(i);
                const tileGroup = new THREE.Group();
                tileGroup.position.set(coords.x, 0, coords.z);
                tileGroup.rotation.y = coords.rotY;

                // Breite Straße (Fahrbahn 14m breit, 20m lang)
                const roadGeo = new THREE.BoxGeometry(14, 0.4, 20);
                const road = new THREE.Mesh(roadGeo, asphaltMat);
                road.position.set(0, 0.2, 0);
                road.receiveShadow = true;
                road.userData = { tileIndex: i };
                tileGroup.add(road);
                this.tileMeshes[i] = road;

                // Gelber Mittelstreifen
                const laneGeo = new THREE.BoxGeometry(0.35, 0.42, 10);
                const lane = new THREE.Mesh(laneGeo, laneMat);
                lane.position.set(0, 0.21, 0);
                tileGroup.add(lane);

                // Bordstein & Gehweg rechts (zum Grundstück hin)
                const curbGeo = new THREE.BoxGeometry(0.5, 0.55, 20);
                const curb = new THREE.Mesh(curbGeo, curbMat);
                curb.position.set(7.25, 0.27, 0);
                curb.castShadow = true;
                tileGroup.add(curb);

                const walkGeo = new THREE.BoxGeometry(5, 0.5, 20);
                const walk = new THREE.Mesh(walkGeo, sidewalkMat);
                walk.position.set(10, 0.25, 0);
                walk.receiveShadow = true;
                tileGroup.add(walk);

                // Straßenlaterne am Gehweg
                this.addStreetLamp(tileGroup, 9, 0);

                // Großes Grundstück rechts neben dem Gehweg (x = 24m)
                const plotGroup = new THREE.Group();
                plotGroup.position.set(24, 0, 0);
                tileGroup.add(plotGroup);
                this.tilePlotGroups[i] = plotGroup;

                // Initial als Wildnis (Stufe 0) rendern
                this.renderPlotState(i, 0, null, false);

                this.scene.add(tileGroup);
            }
        }

        addStreetLamp(parent, x, z) {
            const poleGeo = new THREE.CylinderGeometry(0.12, 0.15, 6, 8);
            const poleMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.6 });
            const pole = new THREE.Mesh(poleGeo, poleMat);
            pole.position.set(x, 3, z);
            pole.castShadow = true;
            parent.add(pole);

            // Lampenschirm & Licht
            const lampGeo = new THREE.SphereGeometry(0.4, 8, 8);
            const lampMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
            const lamp = new THREE.Mesh(lampGeo, lampMat);
            lamp.position.set(x - 0.5, 5.8, z);
            parent.add(lamp);
        }

        // ---------------------------------------------------------------------
        // 5. DAS 3D-COCKPIT (FAHRERSITZ, LENKRAD, SCHEIBEN & MOTORHAUBE)
        // ---------------------------------------------------------------------
        buildCockpit() {
            this.cockpitRig = new THREE.Group();

            // 1. Sichtbare Motorhaube vorn durch die Windschutzscheibe
            const hoodGeo = new THREE.BoxGeometry(3.6, 0.6, 4.2);
            this.carHoodMat = new THREE.MeshStandardMaterial({
                color: 0xef4444,
                roughness: 0.25,
                metalness: 0.7,
            });
            this.carHoodMesh = new THREE.Mesh(hoodGeo, this.carHoodMat);
            this.carHoodMesh.position.set(0, 0.6, 2.5);
            this.carHoodMesh.castShadow = true;
            this.cockpitRig.add(this.carHoodMesh);

            // 2. Armaturenbrett (Dashboard aus dunklem Leder/Charcoal)
            const dashMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.85 });
            const dashGeo = new THREE.BoxGeometry(3.8, 0.75, 1.4);
            const dash = new THREE.Mesh(dashGeo, dashMat);
            dash.position.set(0, 0.85, 0.65);
            this.cockpitRig.add(dash);

            // 3. Windschutzscheiben-A-Säulen (Rahmen)
            const pillarMat = new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.7 });
            const pillarGeo = new THREE.BoxGeometry(0.18, 2.2, 0.25);

            // Linke A-Säule
            const leftPillar = new THREE.Mesh(pillarGeo, pillarMat);
            leftPillar.position.set(-1.8, 1.6, 0.6);
            leftPillar.rotation.z = -0.3;
            leftPillar.rotation.x = -0.3;
            this.cockpitRig.add(leftPillar);

            // Rechte A-Säule
            const rightPillar = new THREE.Mesh(pillarGeo, pillarMat);
            rightPillar.position.set(1.8, 1.6, 0.6);
            rightPillar.rotation.z = 0.3;
            rightPillar.rotation.x = -0.3;
            this.cockpitRig.add(rightPillar);

            // 4. Dach-Querträger mit Rückspiegel
            const roofGeo = new THREE.BoxGeometry(3.8, 0.2, 0.5);
            const roof = new THREE.Mesh(roofGeo, pillarMat);
            roof.position.set(0, 2.45, 0.2);
            this.cockpitRig.add(roof);

            const mirrorGeo = new THREE.BoxGeometry(0.9, 0.28, 0.08);
            const mirrorMat = new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.1, metalness: 0.9 });
            const mirror = new THREE.Mesh(mirrorGeo, mirrorMat);
            mirror.position.set(0, 2.25, 0.3);
            this.cockpitRig.add(mirror);

            // 5. Sport-Lenkrad auf der Fahrerseite (Links)
            const wheelGroup = new THREE.Group();
            wheelGroup.position.set(-0.65, 0.95, 0.2);
            wheelGroup.rotation.x = -0.4;

            const ringGeo = new THREE.TorusGeometry(0.42, 0.045, 8, 24);
            const wheelMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.7 });
            const ring = new THREE.Mesh(ringGeo, wheelMat);
            wheelGroup.add(ring);

            // Hupen-Mitte
            const hubGeo = new THREE.CylinderGeometry(0.12, 0.12, 0.06, 12);
            hubGeo.rotateX(Math.PI / 2);
            const hub = new THREE.Mesh(hubGeo, wheelMat);
            wheelGroup.add(hub);

            this.cockpitRig.add(wheelGroup);
            this.steeringWheel = wheelGroup;

            // 6. Die Kamera im Fahrersitz platzieren (Augenhöhe)
            this.camera.position.set(-0.65, 1.45, -0.1);
            this.cockpitRig.add(this.camera);

            this.scene.add(this.cockpitRig);
        }

        // ---------------------------------------------------------------------
        // 6. VISUELLE GRUNDSTÜCKS-EVOLUTION (LEBENSGROSS NEBEN DEM BEIFAHRER)
        // ---------------------------------------------------------------------
        renderPlotState(tileIndex, level, ownerColor = null, isMortgaged = false) {
            const group = this.tilePlotGroups[tileIndex];
            if (!group) return;

            while (group.children.length > 0) {
                group.remove(group.children[0]);
            }

            // Großes Grundstück: 22m x 22m
            const plotMat = new THREE.MeshStandardMaterial({
                color: level === 0 ? 0x3f3f46 : 0x15803d, // Verwilderter Schotterboden vs. sattes Grün
                roughness: 0.9,
            });
            const plotGeo = new THREE.BoxGeometry(22, 0.4, 20);
            const plot = new THREE.Mesh(plotGeo, plotMat);
            plot.position.set(0, 0.2, 0);
            plot.receiveShadow = true;
            group.add(plot);

            const hexColor = ownerColor || '#3b82f6';
            const playerMat = new THREE.MeshStandardMaterial({ color: hexColor, roughness: 0.5 });

            // STUFE 0: WILDNIS (Unkraut, Felsen & Holz-Verkaufsschild direkt am Gehweg)
            if (level === 0) {
                // Steine & Schutt
                const rockGeo = new THREE.DodecahedronGeometry(1.2, 0);
                const rockMat = new THREE.MeshStandardMaterial({ color: 0x52525b, roughness: 0.9 });
                const r1 = new THREE.Mesh(rockGeo, rockMat);
                r1.position.set(-4, 0.8, -3);
                r1.castShadow = true;
                group.add(r1);

                const r2 = new THREE.Mesh(rockGeo, rockMat);
                r2.position.set(3, 0.7, 4);
                r2.scale.set(0.8, 0.8, 0.8);
                group.add(r2);

                // Großes Holzschild "ZU VERKAUFEN" am Gehwegrand
                const postGeo = new THREE.CylinderGeometry(0.12, 0.12, 3.5);
                const woodMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.8 });
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

            // STUFE 1: ERSCHLOSSEN (Gepflegter Zaun, gepflasterte Einfahrt & Fahnenmast)
            if (level >= 1) {
                // Weißer / Spielerfarbener Zaun entlang des Gehwegs (x = -10.5)
                const fenceGeo = new THREE.BoxGeometry(0.2, 1.3, 19);
                const fence = new THREE.Mesh(fenceGeo, playerMat);
                fence.position.set(-10.5, 0.9, 0);
                fence.castShadow = true;
                group.add(fence);

                // Einfahrt mit Pfeilern
                const pillarGeo = new THREE.BoxGeometry(0.6, 2.2, 0.6);
                const pillarMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.6 });
                const pLeft = new THREE.Mesh(pillarGeo, pillarMat);
                pLeft.position.set(-10.5, 1.1, -3);
                group.add(pLeft);

                const pRight = new THREE.Mesh(pillarGeo, pillarMat);
                pRight.position.set(-10.5, 1.1, 3);
                group.add(pRight);

                // Fahnenmast mit Besitzer-Fahne
                const flagPoleGeo = new THREE.CylinderGeometry(0.08, 0.08, 6);
                const pole = new THREE.Mesh(flagPoleGeo, pillarMat);
                pole.position.set(-9.5, 3, -4);
                group.add(pole);

                const flagGeo = new THREE.BoxGeometry(0.05, 1.2, 1.8);
                const flag = new THREE.Mesh(flagGeo, playerMat);
                flag.position.set(-9.5, 5.2, -4.9);
                group.add(flag);
            }

            // STUFE 2–4: 1 BIS 3 MODERNE HÄUSER
            if (level >= 2 && level <= 4) {
                const count = level - 1;
                const offsetsZ = count === 1 ? [0] : count === 2 ? [-5, 5] : [-6, 0, 6];

                offsetsZ.forEach(oz => {
                    const houseGroup = new THREE.Group();
                    houseGroup.position.set(0, 0, oz);

                    // 2-stöckiger Hauskörper
                    const bodyGeo = new THREE.BoxGeometry(10, 4.8, 5.5);
                    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.5 });
                    const body = new THREE.Mesh(bodyGeo, bodyMat);
                    body.position.set(0, 2.6, 0);
                    body.castShadow = true;
                    body.receiveShadow = true;
                    houseGroup.add(body);

                    // Satteldach in Spielerfarbe
                    const roofGeo = new THREE.ConeGeometry(5.2, 2.2, 4);
                    const roof = new THREE.Mesh(roofGeo, playerMat);
                    roof.position.set(0, 5.8, 0);
                    roof.rotation.y = Math.PI / 4;
                    roof.castShadow = true;
                    houseGroup.add(roof);

                    // Große Fensterfronten (Warmes Licht)
                    const winGeo = new THREE.BoxGeometry(0.1, 1.8, 2.8);
                    const winMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
                    const win = new THREE.Mesh(winGeo, winMat);
                    win.position.set(-5.05, 2.8, 0);
                    houseGroup.add(win);

                    group.add(houseGroup);
                });
            }

            // STUFE 5: LUXUS-VILLA / HOTEL MIT POOL & PALMEN
            if (level === 5) {
                const hotelGroup = new THREE.Group();

                // Großes 3-stöckiges Luxus-Gebäude
                const mainGeo = new THREE.BoxGeometry(14, 8.5, 14);
                const mainMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, roughness: 0.3, metalness: 0.2 });
                const main = new THREE.Mesh(mainGeo, mainMat);
                main.position.set(2, 4.45, 0);
                main.castShadow = true;
                hotelGroup.add(main);

                // Glas-Fassade zum Spieler hin
                const glassGeo = new THREE.BoxGeometry(0.2, 6.5, 10);
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.8 });
                const glass = new THREE.Mesh(glassGeo, glassMat);
                glass.position.set(-5.1, 4.5, 0);
                hotelGroup.add(glass);

                // Großer beleuchteter Swimming Pool im Vorgarten
                const poolGeo = new THREE.BoxGeometry(6, 0.3, 8);
                const poolMat = new THREE.MeshBasicMaterial({ color: 0x06b6d4 });
                const pool = new THREE.Mesh(poolGeo, poolMat);
                pool.position.set(-8, 0.45, 0);
                hotelGroup.add(pool);

                // Palmen am Pool
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
            const crownMat = new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.8 });
            const crown = new THREE.Mesh(crownGeo, crownMat);
            crown.position.set(x, 5.2, z);
            crown.scale.set(1.4, 0.5, 1.4);
            parent.add(crown);
        }

        // ---------------------------------------------------------------------
        // 7. FAHRT-ANIMATION AUS DER EGO-PERSPEKTIVE
        // ---------------------------------------------------------------------
        driveToTile(fromIndex, toIndex, onComplete) {
            this.isDriving = true;
            this.audio.playEngine();

            // Während der Fahrt: Blick geradeaus auf die Straße
            this.targetYaw = 0.0;
            this.targetPitch = 0.0;

            const steps = (toIndex >= fromIndex) ? (toIndex - fromIndex) : (40 - fromIndex + toIndex);
            const waypoints = [];
            for (let s = 1; s <= steps; s++) {
                const idx = (fromIndex + s) % 40;
                waypoints.push(this.getTileCoords(idx));
            }

            let currentStep = 0;
            const animateStep = () => {
                if (currentStep >= waypoints.length) {
                    this.isDriving = false;
                    // Am Ziel angekommen: Kamera schwenkt sanft nach rechts aufs Grundstück!
                    this.targetYaw = -0.55;
                    this.targetPitch = -0.05;
                    if (onComplete) onComplete();
                    return;
                }

                const target = waypoints[currentStep];
                const startPos = this.cockpitRig.position.clone();
                // Auto fährt auf der rechten Fahrspur (x = +3.5 relativ zur Straßenmitte)
                const targetPos = new THREE.Vector3(target.x, 0, target.z);

                const startTime = performance.now();
                const duration = Math.max(140, 480 - steps * 25);

                const stepLoop = (now) => {
                    const progress = Math.min((now - startTime) / duration, 1);
                    this.cockpitRig.position.lerpVectors(startPos, targetPos, progress);
                    this.cockpitRig.rotation.y = THREE.MathUtils.lerp(this.cockpitRig.rotation.y, target.rotY, 0.2);

                    if (progress < 1) {
                        requestAnimationFrame(stepLoop);
                    } else {
                        currentStep++;
                        animateStep();
                    }
                };
                requestAnimationFrame(stepLoop);
            };

            animateStep();
        }

        // ---------------------------------------------------------------------
        // 8. INTERAKTION: BLICKSTEUERUNG IM COCKPIT
        // ---------------------------------------------------------------------
        bindEvents() {
            const c = this.container;

            // Touch / Drag zum Umschauen im Auto
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

            // Blick-Quick-Toggles
            this.ui.btnLookPlot.addEventListener('click', () => {
                this.targetYaw = -0.65; // Aus dem Beifahrerfenster schauen
                this.targetPitch = -0.05;
            });

            this.ui.btnLookRoad.addEventListener('click', () => {
                this.targetYaw = 0.0; // Geradeaus auf die Straße schauen
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

                // Motorhauben-Farbe auf eigene Spielerfarbe anpassen
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
                        // Position direkt setzen falls initial
                        const coords = this.getTileCoords(activePlayer.position || 0);
                        this.cockpitRig.position.set(coords.x, 0, coords.z);
                        this.cockpitRig.rotation.y = coords.rotY;
                        this.updateNaviScreen(newState, activePlayer);
                    }
                } else if (!this.isDriving) {
                    const coords = this.getTileCoords(activePlayer.position || 0);
                    this.cockpitRig.position.set(coords.x, 0, coords.z);
                    this.cockpitRig.rotation.y = coords.rotY;
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

            // Farbgruppe Badge
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
