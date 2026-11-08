/**
 * UNDER THE HOOD 🏎️ — 3D Three.js Client & Game Engine
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
            } catch (e) {
                console.warn('Audio tone error', e);
            }
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
                osc.frequency.setValueAtTime(75, this.ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(220, this.ctx.currentTime + 0.4);
                gain.gain.setValueAtTime(0.12, this.ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, this.ctx.currentTime + 0.45);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start();
                osc.stop(this.ctx.currentTime + 0.45);
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
    // 2. HAUPTKLASSE: HOOD GAME
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
            this.isAnimatingCar = false;

            // Kamera-Steuerung
            this.camTarget = new THREE.Vector3(0, 0, 0);
            this.camOrbit = { radius: 105, theta: Math.PI / 4, phi: Math.PI / 3.4 };
            this.isDragging = false;
            this.prevTouch = { x: 0, y: 0 };
            this.pinchDist = 0;

            // Raycasting (Klick auf Grundstücke)
            this.raycaster = new THREE.Raycaster();
            this.pointer = new THREE.Vector2();

            // Dom-Elemente
            this.ui = {
                roomBadge: document.getElementById('room-badge-code'),
                cashBadge: document.getElementById('cash-badge-amount'),
                turnPill: document.getElementById('turn-pill-text'),
                tickerText: document.getElementById('ticker-text'),
                playersRow: document.getElementById('players-row'),
                actionDeck: document.getElementById('action-deck'),
                diceDisplay: document.getElementById('dice-display'),
                btnRoll: document.getElementById('btn-roll'),
                btnGroupBuy: document.getElementById('btn-group-buy'),
                btnBuy: document.getElementById('btn-buy'),
                btnPass: document.getElementById('btn-pass'),
                btnEndTurn: document.getElementById('btn-end-turn'),
                btnBail: document.getElementById('btn-bail'),
                // Modals
                lobbyModal: document.getElementById('modal-lobby'),
                sheetBackdrop: document.getElementById('sheet-backdrop'),
                sheetTitle: document.getElementById('sheet-title'),
                sheetPrice: document.getElementById('sheet-price'),
                sheetOwner: document.getElementById('sheet-owner'),
                sheetTable: document.getElementById('sheet-table'),
                sheetBtnBuild: document.getElementById('sheet-btn-build'),
                sheetBtnMortgage: document.getElementById('sheet-btn-mortgage'),
                winnerModal: document.getElementById('modal-winner'),
                winnerText: document.getElementById('winner-text'),
            };

            this.selectedTileIndex = null;
        }

        init() {
            // URL Params prüfen
            const urlParams = new URLSearchParams(window.location.search);
            this.roomCode = (urlParams.get('room') || '').toUpperCase();
            this.playerToken = localStorage.getItem('quarxl_hood_token_' + this.roomCode) || null;

            this.setupThree();
            this.buildBoard();
            this.bindEvents();

            if (this.roomCode) {
                this.loadRoomState();
                this.startPolling();
            } else {
                // Kein Raum angegeben -> Zeige Erstellungs-Modal
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
            this.scene.background = new THREE.Color(0x090d16);
            this.scene.fog = new THREE.FogExp2(0x090d16, 0.005);

            this.camera = new THREE.PerspectiveCamera(40, w / h, 1, 1000);
            this.updateCameraPos();

            this.renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
            this.renderer.setSize(w, h);
            this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
            this.renderer.shadowMap.enabled = true;
            this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
            this.container.appendChild(this.renderer.domElement);

            // Beleuchtung (Warmes Spätnachmittagslicht / Golden Hour)
            const ambient = new THREE.AmbientLight(0x94a3b8, 0.85);
            this.scene.add(ambient);

            const sun = new THREE.DirectionalLight(0xffedd5, 1.35);
            sun.position.set(70, 110, 60);
            sun.castShadow = true;
            sun.shadow.mapSize.width = 1024;
            sun.shadow.mapSize.height = 1024;
            sun.shadow.camera.near = 10;
            sun.shadow.camera.far = 300;
            const d = 80;
            sun.shadow.camera.left = -d;
            sun.shadow.camera.right = d;
            sun.shadow.camera.top = d;
            sun.shadow.camera.bottom = -d;
            sun.shadow.bias = -0.0005;
            this.scene.add(sun);

            // Weiches blaues Himmelslicht von der Gegenseite
            const hemiLight = new THREE.HemisphereLight(0x38bdf8, 0x1e293b, 0.4);
            this.scene.add(hemiLight);

            // Wasserfläche rund um die Insel
            const oceanGeo = new THREE.PlaneGeometry(600, 600);
            const oceanMat = new THREE.MeshStandardMaterial({
                color: 0x0284c7,
                roughness: 0.2,
                metalness: 0.1,
            });
            const ocean = new THREE.Mesh(oceanGeo, oceanMat);
            ocean.rotation.x = -Math.PI / 2;
            ocean.position.y = -1.5;
            ocean.receiveShadow = true;
            this.scene.add(ocean);

            // Insel-Basis (Terrain)
            const islandGeo = new THREE.BoxGeometry(110, 2.5, 110);
            const islandMat = new THREE.MeshStandardMaterial({
                color: 0x1e293b,
                roughness: 0.85,
            });
            const island = new THREE.Mesh(islandGeo, islandMat);
            island.position.y = -0.5;
            island.receiveShadow = true;
            this.scene.add(island);

            // Render-Schleife
            const animate = () => {
                requestAnimationFrame(animate);
                this.renderer.render(this.scene, this.camera);
            };
            animate();

            // Resize Listener
            window.addEventListener('resize', () => {
                const nw = this.container.clientWidth;
                const nh = this.container.clientHeight;
                this.camera.aspect = nw / nh;
                this.camera.updateProjectionMatrix();
                this.renderer.setSize(nw, nh);
            });
        }

        updateCameraPos() {
            const x = this.camTarget.x + this.camOrbit.radius * Math.sin(this.camOrbit.phi) * Math.sin(this.camOrbit.theta);
            const y = this.camTarget.y + this.camOrbit.radius * Math.cos(this.camOrbit.phi);
            const z = this.camTarget.z + this.camOrbit.radius * Math.sin(this.camOrbit.phi) * Math.cos(this.camOrbit.theta);
            this.camera.position.set(x, y, z);
            this.camera.lookAt(this.camTarget);
        }

        // ---------------------------------------------------------------------
        // 4. BOARD-AUFBAU: 40 FELDER IM RUNDKURS (10 JE SEITE)
        // ---------------------------------------------------------------------
        getTileCoords(index) {
            // Rechteckiger Rundkurs von -40 bis +40 auf X und Z
            const half = 42;
            const step = (half * 2) / 10; // 8.4 Einheiten je Feld

            if (index >= 0 && index <= 10) {
                // Unten (Süd): X von -half bis +half, Z = +half
                return { x: -half + index * step, z: half, rotY: 0 };
            } else if (index > 10 && index <= 20) {
                // Rechts (Ost): X = +half, Z von +half bis -half
                const i = index - 10;
                return { x: half, z: half - i * step, rotY: -Math.PI / 2 };
            } else if (index > 20 && index <= 30) {
                // Oben (Nord): X von +half bis -half, Z = -half
                const i = index - 20;
                return { x: half - i * step, z: -half, rotY: Math.PI };
            } else {
                // Links (West): X = -half, Z von -half bis +half
                const i = index - 30;
                return { x: -half, z: -half + i * step, rotY: Math.PI / 2 };
            }
        }

        buildBoard() {
            const asphaltMat = new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.9 });
            const curbMat = new THREE.MeshStandardMaterial({ color: 0xe4e4e7, roughness: 0.7 });
            const laneMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });

            for (let i = 0; i < 40; i++) {
                const coords = this.getTileCoords(i);
                const tileGroup = new THREE.Group();
                tileGroup.position.set(coords.x, 0, coords.z);
                tileGroup.rotation.y = coords.rotY;

                // Straßenabschnitt (Fahrbahn)
                const roadGeo = new THREE.BoxGeometry(8, 0.4, 7);
                const road = new THREE.Mesh(roadGeo, asphaltMat);
                road.position.set(0, 0.2, 0);
                road.receiveShadow = true;
                road.userData = { tileIndex: i };
                tileGroup.add(road);
                this.tileMeshes[i] = road;

                // Mittelstreifen
                const laneGeo = new THREE.BoxGeometry(4, 0.42, 0.35);
                const lane = new THREE.Mesh(laneGeo, laneMat);
                lane.position.set(0, 0.21, 0);
                tileGroup.add(lane);

                // Bordstein
                const curbGeo = new THREE.BoxGeometry(8, 0.55, 0.6);
                const curb = new THREE.Mesh(curbGeo, curbMat);
                curb.position.set(0, 0.25, -3.4);
                curb.castShadow = true;
                tileGroup.add(curb);

                // Grundstücks-Container (hinter der Straße, z = -8)
                const plotGroup = new THREE.Group();
                plotGroup.position.set(0, 0, -8);
                tileGroup.add(plotGroup);
                this.tilePlotGroups[i] = plotGroup;

                // Initiales Aussehen (Wildnis Stufe 0)
                this.renderPlotState(i, 0, null, false);

                this.scene.add(tileGroup);
            }

            // Zentrum der Insel dekorieren (Downtown Miniatur-Skyline & Park)
            this.buildCityCenter();
        }

        buildCityCenter() {
            const centerGroup = new THREE.Group();
            const bldMat1 = new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.6, metalness: 0.2 });
            const bldMat2 = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.4, metalness: 0.4 });
            const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.2, metalness: 0.8 });

            // 5 Miniatur-Wolkenkratzer
            const towers = [
                { w: 10, h: 28, d: 10, x: -10, z: -10 },
                { w: 12, h: 36, d: 8, x: 8, z: -8 },
                { w: 8, h: 22, d: 12, x: -8, z: 10 },
                { w: 14, h: 42, d: 10, x: 10, z: 12 },
                { w: 6, h: 20, d: 6, x: 0, z: 0 },
            ];

            towers.forEach((t, idx) => {
                const geo = new THREE.BoxGeometry(t.w, t.h, t.d);
                const mesh = new THREE.Mesh(geo, idx % 2 === 0 ? bldMat1 : bldMat2);
                mesh.position.set(t.x, t.h / 2, t.z);
                mesh.castShadow = true;
                mesh.receiveShadow = true;
                centerGroup.add(mesh);

                // Glas-Fensterband
                const bandGeo = new THREE.BoxGeometry(t.w + 0.1, t.h * 0.7, t.d + 0.1);
                const band = new THREE.Mesh(bandGeo, glassMat);
                band.position.set(t.x, t.h / 2, t.z);
                centerGroup.add(band);
            });

            this.scene.add(centerGroup);
        }

        // ---------------------------------------------------------------------
        // 5. VISUELLE EVOLUTION DER GRUNDSTÜCKE (STUFEN 0 BIS 5)
        // ---------------------------------------------------------------------
        renderPlotState(tileIndex, level, ownerColor = null, isMortgaged = false) {
            const group = this.tilePlotGroups[tileIndex];
            if (!group) return;

            // Alte Objekte entfernen
            while (group.children.length > 0) {
                group.remove(group.children[0]);
            }

            // Basis-Grasboden
            const groundMat = new THREE.MeshStandardMaterial({
                color: level === 0 ? 0x475569 : 0x16a34a, // Trockenes Steppengras vs. saftiges Grün
                roughness: 0.9,
            });
            const groundGeo = new THREE.BoxGeometry(7.6, 0.3, 7.6);
            const ground = new THREE.Mesh(groundGeo, groundMat);
            ground.position.y = 0.15;
            ground.receiveShadow = true;
            group.add(ground);

            const hexColor = ownerColor || '#3b82f6';
            const playerMat = new THREE.MeshStandardMaterial({ color: hexColor, roughness: 0.5 });

            // STUFE 0: WILDNIS (Unberührt, Steine, Verkaufsschild)
            if (level === 0) {
                // Steine / Schotter
                const rockGeo = new THREE.DodecahedronGeometry(0.5, 0);
                const rockMat = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.9 });
                const rock1 = new THREE.Mesh(rockGeo, rockMat);
                rock1.position.set(-1.8, 0.4, 0.5);
                rock1.castShadow = true;
                group.add(rock1);

                const rock2 = new THREE.Mesh(rockGeo, rockMat);
                rock2.position.set(1.5, 0.35, -1.2);
                rock2.scale.set(0.7, 0.7, 0.7);
                group.add(rock2);

                // Hölzernes Verkaufsschild
                const postGeo = new THREE.CylinderGeometry(0.08, 0.08, 1.6);
                const woodMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.8 });
                const post = new THREE.Mesh(postGeo, woodMat);
                post.position.set(0, 0.8, 1.8);
                group.add(post);

                const signGeo = new THREE.BoxGeometry(1.6, 0.8, 0.1);
                const signMat = new THREE.MeshStandardMaterial({ color: 0xfef08a });
                const sign = new THREE.Mesh(signGeo, signMat);
                sign.position.set(0, 1.3, 1.8);
                sign.castShadow = true;
                group.add(sign);
                return;
            }

            // STUFE 1: ERSCHLOSSEN (Gepflegter Rasen, Zaun in Spielerfarbe, Laterne)
            if (level >= 1) {
                // Moderner Lattenzaun vorn
                const fenceGeo = new THREE.BoxGeometry(7.2, 0.5, 0.15);
                const fence = new THREE.Mesh(fenceGeo, playerMat);
                fence.position.set(0, 0.45, 3.4);
                fence.castShadow = true;
                group.add(fence);

                // Straßenlaterne
                const poleGeo = new THREE.CylinderGeometry(0.08, 0.08, 2.5);
                const poleMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.5 });
                const pole = new THREE.Mesh(poleGeo, poleMat);
                pole.position.set(3, 1.25, 3);
                pole.castShadow = true;
                group.add(pole);

                const lampGeo = new THREE.SphereGeometry(0.25, 8, 8);
                const lampMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
                const lamp = new THREE.Mesh(lampGeo, lampMat);
                lamp.position.set(3, 2.5, 3);
                group.add(lamp);

                // Fahne mit Spielerfarbe
                const flagPole = new THREE.Mesh(poleGeo, poleMat);
                flagPole.position.set(-3, 1.25, 3);
                group.add(flagPole);

                const flagGeo = new THREE.BoxGeometry(0.9, 0.5, 0.05);
                const flag = new THREE.Mesh(flagGeo, playerMat);
                flag.position.set(-2.55, 2.2, 3);
                group.add(flag);
            }

            // STUFEN 2–4: 1 BIS 3 HÄUSER
            if (level >= 2 && level <= 4) {
                const houseCount = level - 1; // 1, 2 oder 3
                const offsets = houseCount === 1 ? [0] : houseCount === 2 ? [-1.6, 1.6] : [-2.2, 0, 2.2];

                offsets.forEach((ox) => {
                    const houseGroup = new THREE.Group();
                    houseGroup.position.set(ox, 0, -0.5);

                    // Hauskörper
                    const bodyGeo = new THREE.BoxGeometry(1.8, 1.6, 2.2);
                    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.7 });
                    const body = new THREE.Mesh(bodyGeo, bodyMat);
                    body.position.y = 0.95;
                    body.castShadow = true;
                    body.receiveShadow = true;
                    houseGroup.add(body);

                    // Spitzdach in Spielerfarbe
                    const roofGeo = new THREE.ConeGeometry(1.6, 0.9, 4);
                    const roof = new THREE.Mesh(roofGeo, playerMat);
                    roof.position.y = 2.2;
                    roof.rotation.y = Math.PI / 4;
                    roof.castShadow = true;
                    houseGroup.add(roof);

                    // Fenster (Warm erleuchtet)
                    const winGeo = new THREE.BoxGeometry(0.5, 0.5, 0.1);
                    const winMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
                    const win = new THREE.Mesh(winGeo, winMat);
                    win.position.set(0, 1.1, 1.06);
                    houseGroup.add(win);

                    // Pop-In Animation
                    houseGroup.scale.set(0.1, 0.1, 0.1);
                    this.animatePopIn(houseGroup);
                    group.add(houseGroup);
                });
            }

            // STUFE 5: LUXUS-HOTEL / PRACHTBAU
            if (level === 5) {
                const hotelGroup = new THREE.Group();
                hotelGroup.position.set(0, 0, -0.5);

                // Hauptgebäude (Mehrstöckig, Klinker / Weiß)
                const mainGeo = new THREE.BoxGeometry(5.2, 3.8, 4.4);
                const mainMat = new THREE.MeshStandardMaterial({ color: 0xf1f5f9, roughness: 0.4, metalness: 0.2 });
                const mainBld = new THREE.Mesh(mainGeo, mainMat);
                mainBld.position.y = 2.05;
                mainBld.castShadow = true;
                mainBld.receiveShadow = true;
                hotelGroup.add(mainBld);

                // Glasfassade vorn
                const glassGeo = new THREE.BoxGeometry(4.2, 2.8, 0.2);
                const glassMat = new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.1, metalness: 0.9 });
                const glass = new THREE.Mesh(glassGeo, glassMat);
                glass.position.set(0, 2.05, 2.22);
                hotelGroup.add(glass);

                // Dachterrasse mit Pool
                const roofGeo = new THREE.BoxGeometry(5.4, 0.4, 4.6);
                const roofMat = new THREE.MeshStandardMaterial({ color: 0x334155 });
                const roof = new THREE.Mesh(roofGeo, roofMat);
                roof.position.y = 4.15;
                roof.castShadow = true;
                hotelGroup.add(roof);

                // Pool auf dem Dach
                const poolGeo = new THREE.BoxGeometry(2.4, 0.2, 1.8);
                const poolMat = new THREE.MeshBasicMaterial({ color: 0x06b6d4 });
                const pool = new THREE.Mesh(poolGeo, poolMat);
                pool.position.set(0, 4.36, 0);
                hotelGroup.add(pool);

                // Hotel-Schriftzug / Banner in Spielerfarbe
                const signGeo = new THREE.BoxGeometry(3.2, 0.6, 0.2);
                const sign = new THREE.Mesh(signGeo, playerMat);
                sign.position.set(0, 4.75, 1.8);
                hotelGroup.add(sign);

                this.animatePopIn(hotelGroup);
                group.add(hotelGroup);
            }
        }

        animatePopIn(obj) {
            let t = 0;
            const startTime = performance.now();
            const dur = 400; // ms
            const step = (now) => {
                const progress = Math.min((now - startTime) / dur, 1);
                // Elastic bounce ease
                const s = Math.sin(progress * Math.PI * 0.5) * 1.08;
                obj.scale.set(s, s, s);
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    obj.scale.set(1, 1, 1);
                }
            };
            requestAnimationFrame(step);
        }

        // ---------------------------------------------------------------------
        // 6. FAHRZEUGE (SPIELER-TOKENS) & FAHR-ANIMATION
        // ---------------------------------------------------------------------
        createCarMesh(player) {
            const carGroup = new THREE.Group();
            const color = player.color || '#ef4444';

            // Karosserie
            const bodyMat = new THREE.MeshStandardMaterial({
                color: color,
                roughness: 0.3,
                metalness: 0.6,
            });
            const bodyGeo = new THREE.BoxGeometry(2.6, 0.7, 1.4);
            const body = new THREE.Mesh(bodyGeo, bodyMat);
            body.position.y = 0.55;
            body.castShadow = true;
            carGroup.add(body);

            // Dach / Scheiben
            const topMat = new THREE.MeshStandardMaterial({
                color: 0x0f172a,
                roughness: 0.1,
                metalness: 0.9,
            });
            const topGeo = new THREE.BoxGeometry(1.4, 0.6, 1.2);
            const top = new THREE.Mesh(topGeo, topMat);
            top.position.set(-0.2, 1.15, 0);
            top.castShadow = true;
            carGroup.add(top);

            // 4 Räder
            const wheelMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.8 });
            const wheelGeo = new THREE.CylinderGeometry(0.35, 0.35, 0.3, 12);
            wheelGeo.rotateZ(Math.PI / 2);

            const wheelPositions = [
                { x: 0.8, y: 0.35, z: 0.7 },
                { x: -0.8, y: 0.35, z: 0.7 },
                { x: 0.8, y: 0.35, z: -0.7 },
                { x: -0.8, y: 0.35, z: -0.7 },
            ];

            wheelPositions.forEach((wp) => {
                const wheel = new THREE.Mesh(wheelGeo, wheelMat);
                wheel.position.set(wp.x, wp.y, wp.z);
                wheel.castShadow = true;
                carGroup.add(wheel);
            });

            // Scheinwerfer
            const lightMat = new THREE.MeshBasicMaterial({ color: 0xfef08a });
            const lightGeo = new THREE.BoxGeometry(0.1, 0.2, 0.3);
            const l1 = new THREE.Mesh(lightGeo, lightMat);
            l1.position.set(1.3, 0.6, 0.45);
            const l2 = new THREE.Mesh(lightGeo, lightMat);
            l2.position.set(1.3, 0.6, -0.45);
            carGroup.add(l1);
            carGroup.add(l2);

            return carGroup;
        }

        updatePlayersAndCars(players) {
            players.forEach((p, idx) => {
                if (!this.carMeshes[p.id]) {
                    const car = this.createCarMesh(p);
                    this.scene.add(car);
                    this.carMeshes[p.id] = car;

                    // Initial platzieren
                    const coords = this.getTileCoords(p.position || 0);
                    // Versatz für mehrere Autos auf demselben Feld
                    const offsetZ = (idx % 2 === 0 ? 1 : -1) * 0.9;
                    car.position.set(coords.x, 0.2, coords.z + offsetZ);
                    car.rotation.y = coords.rotY;
                }
            });
        }

        animateCarMovement(playerId, fromIndex, toIndex, onComplete) {
            const car = this.carMeshes[playerId];
            if (!car) {
                if (onComplete) onComplete();
                return;
            }

            this.isAnimatingCar = true;
            this.audio.playEngine();

            // Wegpunkte sammeln
            const steps = (toIndex >= fromIndex) ? (toIndex - fromIndex) : (40 - fromIndex + toIndex);
            const waypoints = [];
            for (let s = 1; s <= steps; s++) {
                const tileIdx = (fromIndex + s) % 40;
                waypoints.push(this.getTileCoords(tileIdx));
            }

            let currentStep = 0;
            const animateNextStep = () => {
                if (currentStep >= waypoints.length) {
                    this.isAnimatingCar = false;
                    // Sanftes Einfedern beim Bremsen
                    this.bounceCar(car);
                    if (onComplete) onComplete();
                    return;
                }

                const target = waypoints[currentStep];
                const startPos = car.position.clone();
                const targetPos = new THREE.Vector3(target.x, 0.2, target.z);
                const startTime = performance.now();
                const stepDuration = Math.max(120, 450 - steps * 25); // Je mehr Felder, desto schneller

                const stepLoop = (now) => {
                    const progress = Math.min((now - startTime) / stepDuration, 1);
                    car.position.lerpVectors(startPos, targetPos, progress);
                    car.rotation.y = THREE.MathUtils.lerp(car.rotation.y, target.rotY, 0.15);

                    // Kamera folgt weich dem fahrenden Auto
                    this.camTarget.lerp(car.position, 0.08);
                    this.updateCameraPos();

                    if (progress < 1) {
                        requestAnimationFrame(stepLoop);
                    } else {
                        currentStep++;
                        animateNextStep();
                    }
                };
                requestAnimationFrame(stepLoop);
            };

            animateNextStep();
        }

        bounceCar(car) {
            let t = 0;
            const startY = 0.2;
            const bounce = () => {
                t += 0.2;
                car.position.y = startY + Math.sin(t) * 0.25 * Math.exp(-t * 0.8);
                if (t < Math.PI * 2) {
                    requestAnimationFrame(bounce);
                } else {
                    car.position.y = startY;
                }
            };
            bounce();
        }

        // ---------------------------------------------------------------------
        // 7. INTERAKTION: RAYCASTING & TOUCH-ORBIT
        // ---------------------------------------------------------------------
        bindEvents() {
            // Touch & Drag für Kamera
            const c = this.container;
            c.addEventListener('pointerdown', (e) => {
                this.isDragging = true;
                this.prevTouch = { x: e.clientX, y: e.clientY };
            });

            window.addEventListener('pointermove', (e) => {
                if (!this.isDragging) return;
                const dx = e.clientX - this.prevTouch.x;
                const dy = e.clientY - this.prevTouch.y;

                this.camOrbit.theta -= dx * 0.008;
                this.camOrbit.phi = Math.max(0.4, Math.min(Math.PI / 2.2, this.camOrbit.phi + dy * 0.008));
                this.updateCameraPos();

                this.prevTouch = { x: e.clientX, y: e.clientY };
            });

            window.addEventListener('pointerup', () => {
                this.isDragging = false;
            });

            // Zoom per Mausrad
            c.addEventListener('wheel', (e) => {
                e.preventDefault();
                this.camOrbit.radius = Math.max(40, Math.min(180, this.camOrbit.radius + e.deltaY * 0.08));
                this.updateCameraPos();
            }, { passive: false });

            // Klick auf Kachel (Inspektion)
            c.addEventListener('click', (e) => {
                const rect = c.getBoundingClientRect();
                this.pointer.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
                this.pointer.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;

                this.raycaster.setFromCamera(this.pointer, this.camera);
                const intersects = this.raycaster.intersectObjects(this.tileMeshes);

                if (intersects.length > 0) {
                    const hit = intersects[0];
                    const tileIdx = hit.object.userData.tileIndex;
                    if (tileIdx !== undefined) {
                        this.openPropertySheet(tileIdx);
                    }
                }
            });

            // UI Buttons
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

            // Restart Button (ohne Inline-Handler wg. CSP)
            const btnRestart = document.getElementById('btn-restart-game');
            if (btnRestart) {
                btnRestart.addEventListener('click', () => window.location.reload());
            }
        }

        // ---------------------------------------------------------------------
        // 8. API-AUFRUFE & GAME-LOOP
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
                console.error('API Error', e);
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
            } catch (e) {
                console.warn('Sync error', e);
            }
        }

        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => {
                if (!this.isAnimatingCar) {
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
            }

            // Topbar & Badges
            this.ui.roomBadge.textContent = this.roomCode;
            if (me) {
                this.ui.cashBadge.textContent = `${me.cash} $`;
            }

            // Letzter Ticker-Eintrag
            if (newState.log && newState.log.length > 0) {
                const latest = newState.log[newState.log.length - 1];
                this.ui.tickerText.textContent = latest.text;
            }

            // Spieler-Reihe
            this.renderPlayersRow(newState.players, newState.current_player_index);

            // 3D-Autos aktualisieren
            this.updatePlayersAndCars(newState.players);

            // Grundstücke 3D aktualisieren
            if (newState.tiles) {
                newState.tiles.forEach((t) => {
                    const owner = newState.players.find(p => p.id === t.owner);
                    const ownerColor = owner ? owner.color : null;
                    this.renderPlotState(t.index, t.level, ownerColor, t.mortgaged);
                });
            }

            // Würfel-Ergebnis
            if (newState.last_dice) {
                document.getElementById('die-1').textContent = newState.last_dice[0];
                document.getElementById('die-2').textContent = newState.last_dice[1];
            }

            // Prüfen, ob eine Bewegung animiert werden muss
            const activePlayer = newState.players[newState.current_player_index];
            if (oldState && activePlayer && !this.isAnimatingCar) {
                const oldActive = oldState.players.find(p => p.id === activePlayer.id);
                if (oldActive && oldActive.position !== activePlayer.position) {
                    this.animateCarMovement(activePlayer.id, oldActive.position, activePlayer.position);
                }
            }

            // Aktions-Buttons updaten
            this.updateActionUI(newState, activePlayer);

            // Sieger-Bildschirm?
            if (newState.status === 'finished' && newState.winner_id) {
                const winner = newState.players.find(p => p.id === newState.winner_id);
                if (winner) {
                    this.ui.winnerText.textContent = `👑 ${winner.name} hat alle Mitspieler ausgestochen und gewinnt die Hood!`;
                    this.ui.winnerModal.classList.add('open');
                }
            }

            // Lobby-Status Modal
            if (newState.status === 'lobby') {
                this.renderLobbyModal(newState);
            } else {
                this.ui.lobbyModal.classList.remove('open');
            }
        }

        renderPlayersRow(players, activeIdx) {
            this.ui.playersRow.innerHTML = '';
            players.forEach((p, idx) => {
                const chip = document.createElement('div');
                chip.className = `hood-player-chip ${idx === activeIdx ? 'active' : ''}`;
                chip.innerHTML = `
                    <div class="hood-chip-dot" style="background-color: ${p.color};"></div>
                    <span>${p.name}</span>
                    <strong style="color: var(--hood-gold);">${p.cash} $</strong>
                `;
                this.ui.playersRow.appendChild(chip);
            });
        }

        updateActionUI(state, activePlayer) {
            const isMyTurn = (activePlayer && activePlayer.id === this.myPlayerId);

            // Alle Aktionsbuttons ausblenden
            this.ui.btnRoll.style.display = 'none';
            this.ui.btnGroupBuy.style.display = 'none';
            this.ui.btnEndTurn.style.display = 'none';
            this.ui.btnBail.style.display = 'none';

            if (!isMyTurn) {
                this.ui.turnPill.textContent = `⏳ ${activePlayer ? activePlayer.name : 'Jemand'} ist am Zug...`;
                this.ui.btnRoll.classList.remove('pulse');
                return;
            }

            this.ui.turnPill.textContent = `👉 Du bist am Zug!`;

            if (state.phase === 'ROLL') {
                this.ui.btnRoll.style.display = 'flex';
                this.ui.btnRoll.classList.add('pulse');
                if (activePlayer.in_jail && activePlayer.cash >= 50) {
                    this.ui.btnBail.style.display = 'flex';
                }
            } else if (state.phase === 'BUY_DECISION') {
                this.ui.btnGroupBuy.style.display = 'flex';
                const tile = state.tiles[activePlayer.position];
                this.ui.btnBuy.textContent = `🏡 KAUFEN (${tile ? tile.price : 0} $)`;
            } else if (state.phase === 'TURN_END') {
                this.ui.btnEndTurn.style.display = 'flex';
            }
        }

        // ---------------------------------------------------------------------
        // 9. SPIELER-AKTIONEN
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

        // ---------------------------------------------------------------------
        // 10. GRUNDSTÜCKS-INSPEKTION & AUSBAU
        // ---------------------------------------------------------------------
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

        // ---------------------------------------------------------------------
        // 11. LOBBY & RAUM-MODAL
        // ---------------------------------------------------------------------
        showLobbyModal(isCreating) {
            this.ui.lobbyModal.classList.add('open');
            const formCreate = document.getElementById('lobby-create-form');
            formCreate.style.display = isCreating ? 'block' : 'none';

            // Farb-Picker
            const colorSwatches = document.querySelectorAll('.hood-color-swatch');
            colorSwatches.forEach(swatch => {
                swatch.addEventListener('click', (e) => {
                    colorSwatches.forEach(s => s.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            // Fahrzeug-Picker
            const vehicleCards = document.querySelectorAll('.hood-vehicle-card');
            vehicleCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    vehicleCards.forEach(c => c.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            // Erstellen-Button
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
                    body: JSON.stringify({ player_name: name, pin: pin, color: color, vehicle: vehicle }),
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

            // Bot hinzufügen
            document.getElementById('btn-add-bot').addEventListener('click', async () => {
                const res = await this.apiPost('add_bot');
                if (res.success) {
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                }
            });

            // Starten-Button
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

            document.getElementById('lobby-room-code').textContent = this.roomCode;
            const list = document.getElementById('lobby-players-list');
            list.innerHTML = '';
            state.players.forEach(p => {
                const li = document.createElement('li');
                li.style.display = 'flex';
                li.style.alignItems = 'center';
                li.style.gap = '0.5rem';
                li.style.padding = '0.5rem 0';
                li.innerHTML = `
                    <div style="width: 12px; height: 12px; border-radius: 50%; background: ${p.color};"></div>
                    <strong>${p.name}</strong>
                    <span style="color: var(--hood-muted); font-size: 0.8rem;">(${p.is_bot ? 'KI' : 'Mensch'})</span>
                `;
                list.appendChild(li);
            });

            // Start & Bot Buttons nur für Host
            document.getElementById('btn-add-bot').style.display = this.isHost ? 'block' : 'none';
            document.getElementById('btn-start-game').style.display = this.isHost ? 'block' : 'none';
        }
    }

    // Starten sobald DOM bereit
    document.addEventListener('DOMContentLoaded', () => {
        window.hoodGame = new HoodGame();
        window.hoodGame.init();
    });
})();
