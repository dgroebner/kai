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
            this.renderedPlotSignatures = {};
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
                naviLevel: document.getElementById('navi-level'),
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
                btnGroupJail: document.getElementById('btn-group-jail'),
                btnBail: document.getElementById('btn-bail'),
                btnUseJailCard: document.getElementById('btn-use-jail-card'),
                btnLookPlot: document.getElementById('btn-look-plot'),
                btnLookRoad: document.getElementById('btn-look-road'),
                lobbyModal: document.getElementById('modal-lobby'),
                sheetBackdrop: document.getElementById('sheet-backdrop'),
                sheetTitle: document.getElementById('sheet-title'),
                sheetPrice: document.getElementById('sheet-price'),
                sheetOwner: document.getElementById('sheet-owner'),
                sheetBtnBuild: document.getElementById('sheet-btn-build'),
                sheetBtnMortgage: document.getElementById('sheet-btn-mortgage'),
                btnPortfolio: document.getElementById('btn-portfolio'),
                modalPortfolio: document.getElementById('modal-portfolio'),
                portfolioClose: document.getElementById('portfolio-close'),
                naviHeaderClickable: document.getElementById('navi-header-clickable'),
                naviMonopolyStatus: document.getElementById('navi-monopoly-status'),
                sheetGroupBadge: document.getElementById('sheet-group-badge'),
                sheetMonopolyInfo: document.getElementById('sheet-monopoly-info'),
                sheetBtnSellBuilding: document.getElementById('sheet-btn-sell-building'),
                sheetBtnSellProperty: document.getElementById('sheet-btn-sell-property'),
                winnerModal: document.getElementById('modal-winner'),
                winnerText: document.getElementById('winner-text'),
                potBadgeAmount: document.getElementById('pot-badge-amount'),
                btnTrade: document.getElementById('btn-trade'),
                modalTrade: document.getElementById('modal-trade'),
                tradeClose: document.getElementById('trade-close'),
                btnSubmitTrade: document.getElementById('btn-submit-trade'),
                modalTradeIncoming: document.getElementById('modal-trade-incoming'),
                btnAcceptTrade: document.getElementById('btn-accept-trade'),
                btnRejectTrade: document.getElementById('btn-reject-trade'),
                modalJailAlert: document.getElementById('modal-jail-alert'),
                jailAlertReason: document.getElementById('jail-alert-reason'),
                btnCloseJailAlert: document.getElementById('btn-close-jail-alert'),
                modalCardAlert: document.getElementById('modal-card-alert'),
                cardAlertIcon: document.getElementById('card-alert-icon'),
                cardAlertBadge: document.getElementById('card-alert-badge'),
                cardAlertTitle: document.getElementById('card-alert-title'),
                cardAlertText: document.getElementById('card-alert-text'),
                cardAlertSub: document.getElementById('card-alert-sub'),
                btnCloseCardAlert: document.getElementById('btn-close-card-alert'),
                modalLeaveConfirm: document.getElementById('modal-leave-confirm'),
                btnConfirmLeave: document.getElementById('btn-confirm-leave'),
                btnCancelLeave: document.getElementById('btn-cancel-leave'),
            };

            this.selectedTileIndex = null;
            this.selectedTradePartnerId = null;
            this.lastSeenJailAlert = null;
            this.lastSeenTradeId = null;
            this.lastSeenCardId = null;
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

            // Ozean rund um die Küste (mit tropischer Kaustik-Wassertextur)
            const oceanGeo = new THREE.PlaneGeometry(2800, 2800);
            const oceanMat = new THREE.MeshStandardMaterial({
                map: this.getOceanTexture(),
                roughness: 0.15,
                metalness: 0.35,
            });
            const ocean = new THREE.Mesh(oceanGeo, oceanMat);
            ocean.rotation.x = -Math.PI / 2;
            ocean.position.y = -2;
            this.scene.add(ocean);

            // Stabile grüne Insel-Landschaft (Ebene Basis bei y = -0.05 mit mehrschichtiger Wiesentextur)
            const islandGeo = new THREE.PlaneGeometry(1200, 1200);
            const islandMat = new THREE.MeshStandardMaterial({
                map: this.getGrassTexture(),
                roughness: 0.85,
            });
            const island = new THREE.Mesh(islandGeo, islandMat);
            island.rotation.x = -Math.PI / 2;
            island.position.y = -0.05;
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

            const roadUVs = [];
            const walkUVs = [];
            const curbUVs = [];

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

                // 1. Straßenband (Höhe y = 0.25, garantiert sauber über dem Terrain bei y = -0.05)
                const rL = p.clone().add(n.clone().multiplyScalar(-roadWidth / 2));
                const rR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2));
                roadPositions.push(rL.x, 0.25, rL.z);
                roadPositions.push(rR.x, 0.25, rR.z);

                const uCoordRoad = i * 0.45;
                roadUVs.push(0, uCoordRoad);
                roadUVs.push(1, uCoordRoad);

                // 2. Bordsteinkante aus Granit (Höhe y = 0.32, Breite 0.6)
                const cL = rR.clone();
                const cR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 + 0.6));
                curbPositions.push(cL.x, 0.32, cL.z);
                curbPositions.push(cR.x, 0.32, cR.z);

                const uCoordCurb = i * 1.5;
                curbUVs.push(0, uCoordCurb);
                curbUVs.push(1, uCoordCurb);

                // 3. Gehwegband rechts (Höhe y = 0.38 aus Naturstein)
                const wL = cR.clone();
                const wR = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 + walkWidth));
                walkPositions.push(wL.x, 0.38, wL.z);
                walkPositions.push(wR.x, 0.38, wR.z);

                const uCoordWalk = i * 0.6;
                walkUVs.push(0, uCoordWalk);
                walkUVs.push(1, uCoordWalk);

                // 4. Fahrbahnmarkierungen: Durchgezogene weiße Außenlinie rechts & gestrichelte gelbe Mittellinie
                if (i < SAMPLES) {
                    const base = i * 2;
                    roadIndices.push(base, base + 1, base + 2);
                    roadIndices.push(base + 1, base + 3, base + 2);

                    curbIndices.push(base, base + 1, base + 2);
                    curbIndices.push(base + 1, base + 3, base + 2);

                    walkIndices.push(base, base + 1, base + 2);
                    walkIndices.push(base + 1, base + 3, base + 2);

                    // Weiße Begrenzungslinie (Höhe y = 0.27)
                    const wl1 = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 - 0.5));
                    const wl2 = p.clone().add(n.clone().multiplyScalar(roadWidth / 2 - 0.25));
                    const nextP = this.trackCurve.getPointAt(((i + 1) % SAMPLES) / SAMPLES);
                    const nextT = this.trackCurve.getTangentAt(((i + 1) % SAMPLES) / SAMPLES).normalize();
                    const nextN = new THREE.Vector3(-nextT.z, 0, nextT.x).normalize();
                    const wl3 = nextP.clone().add(nextN.clone().multiplyScalar(roadWidth / 2 - 0.5));
                    const wl4 = nextP.clone().add(nextN.clone().multiplyScalar(roadWidth / 2 - 0.25));

                    const wIdx = wCount * 4;
                    whiteLinePositions.push(wl1.x, 0.27, wl1.z, wl2.x, 0.27, wl2.z, wl3.x, 0.27, wl3.z, wl4.x, 0.27, wl4.z);
                    whiteLineIndices.push(wIdx, wIdx + 1, wIdx + 2, wIdx + 1, wIdx + 3, wIdx + 2);
                    wCount++;

                    // Gelbe gestrichelte Mittellinie (Höhe y = 0.27, 2 Abschnitte gelb, 1 Abschnitt frei)
                    if (i % 3 < 2) {
                        const yl1 = p.clone().add(n.clone().multiplyScalar(-0.2));
                        const yl2 = p.clone().add(n.clone().multiplyScalar(0.2));
                        const yl3 = nextP.clone().add(nextN.clone().multiplyScalar(-0.2));
                        const yl4 = nextP.clone().add(nextN.clone().multiplyScalar(0.2));

                        const yIdx = yCount * 4;
                        yellowLinePositions.push(yl1.x, 0.27, yl1.z, yl2.x, 0.27, yl2.z, yl3.x, 0.27, yl3.z, yl4.x, 0.27, yl4.z);
                        yellowLineIndices.push(yIdx, yIdx + 1, yIdx + 2, yIdx + 1, yIdx + 3, yIdx + 2);
                        yCount++;
                    }
                }
            }

            // Nahtlose Asphalt-Straße (mit echter ComfyUI PBR-Textur + Normal-Map)
            const roadGeo = new THREE.BufferGeometry();
            roadGeo.setAttribute('position', new THREE.Float32BufferAttribute(roadPositions, 3));
            roadGeo.setAttribute('uv', new THREE.Float32BufferAttribute(roadUVs, 2));
            roadGeo.setIndex(roadIndices);
            roadGeo.computeVertexNormals();

            const roadMat = new THREE.MeshStandardMaterial({
                map: this.loadSeamlessTexture('./textures/asphalt_diffuse.webp', true, 2, 2),
                normalMap: this.loadSeamlessTexture('./textures/asphalt_normal.webp', false, 2, 2),
                normalScale: new THREE.Vector2(1.2, 1.2),
                roughness: 0.85,
                metalness: 0.1,
            });
            const roadMesh = new THREE.Mesh(roadGeo, roadMat);
            roadMesh.receiveShadow = true;
            this.scene.add(roadMesh);

            // Bordstein aus Granit (mit Block-Fugen-Textur)
            const curbGeo = new THREE.BufferGeometry();
            curbGeo.setAttribute('position', new THREE.Float32BufferAttribute(curbPositions, 3));
            curbGeo.setAttribute('uv', new THREE.Float32BufferAttribute(curbUVs, 2));
            curbGeo.setIndex(curbIndices);
            curbGeo.computeVertexNormals();
            const curbMat = new THREE.MeshStandardMaterial({
                map: this.getCurbTexture(),
                roughness: 0.75,
            });
            const curbMesh = new THREE.Mesh(curbGeo, curbMat);
            this.scene.add(curbMesh);

            // Nahtloser Gehweg aus hellem Naturstein (mit Pflasterplatten-Textur)
            const walkGeo = new THREE.BufferGeometry();
            walkGeo.setAttribute('position', new THREE.Float32BufferAttribute(walkPositions, 3));
            walkGeo.setAttribute('uv', new THREE.Float32BufferAttribute(walkUVs, 2));
            walkGeo.setIndex(walkIndices);
            walkGeo.computeVertexNormals();

            const walkMat = new THREE.MeshStandardMaterial({
                map: this.getSidewalkTexture(),
                roughness: 0.78,
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

            // Sanfte Panoramahügel im Hintergrund (mit sicherem Sicherheitsabstand von mindestens 35m zu allen Streckenabschnitten und Grundstücken!)
            const hillConfigs = [
                { x: 0, z: -360, r: 85, h: 45 },
                { x: 310, z: -200, r: 80, h: 40 },
                { x: 340, z: 120, r: 85, h: 42 },
                { x: 0, z: 360, r: 85, h: 46 },
                { x: -320, z: 140, r: 80, h: 40 },
                { x: -330, z: -200, r: 80, h: 44 },
                // Insel-Innere sanfte Hügel (strenger Abstand zur Fahrbahn innen bei dist=145!)
                { x: 20, z: -15, r: 45, h: 16 },
                { x: -20, z: 15, r: 40, h: 14 },
            ];

            hillConfigs.forEach(hc => {
                const hillGeo = new THREE.ConeGeometry(hc.r, hc.h, 24);
                const hill = new THREE.Mesh(hillGeo, hillMat);
                hill.position.set(hc.x, hc.h / 2 - 1, hc.z);
                hill.receiveShadow = true;
                this.scene.add(hill);
            });

            // 2. Flauschige 3D-Sommerwolken am Himmel
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

            // 4. Schattige Laubbäume & Palmen entlang der Landschaft & Küste
            // ZWINGEND: Strenge Kollisions- und Abstandsüberprüfung gegen Straße, Gehweg & Grundstücke!
            const trackSamples = [];
            for (let s = 0; s < 240; s++) {
                trackSamples.push(this.trackCurve.getPointAt(s / 240));
            }

            const plotCenters = [];
            for (let p = 0; p < this.trackPoints.length; p++) {
                const tp = this.trackPoints[p];
                plotCenters.push(tp.pos.clone().add(tp.normal.clone().multiplyScalar(22)));
            }

            // Prüffunktion: Garantiert absolut freien Sicherheitskorridor für Straße und Grundstücke
            const isTreePositionSafe = (x, z) => {
                // 1. Mindestens 22m Abstand zur Fahrbahn-Mittellinie (Fahrbahn ±6.5m, Gehweg bis 13m + Puffer)
                for (let s = 0; s < trackSamples.length; s++) {
                    const dx = x - trackSamples[s].x;
                    const dz = z - trackSamples[s].z;
                    if (dx * dx + dz * dz < 22 * 22) {
                        return false;
                    }
                }

                // 2. Mindestens 18m Abstand zu allen Grundstückszentren
                for (let p = 0; p < plotCenters.length; p++) {
                    const dx = x - plotCenters[p].x;
                    const dz = z - plotCenters[p].z;
                    if (dx * dx + dz * dz < 18 * 18) {
                        return false;
                    }
                }

                // 3. Nicht mitten auf den Spitzen der sanften Innenhügel
                if ((x - 20) ** 2 + (z - (-15)) ** 2 < 14 * 14) return false;
                if ((x - (-20)) ** 2 + (z - 15) ** 2 < 14 * 14) return false;

                return true;
            };

            // A. Zentraler Stadtpark im Insel-Inneren (Laubbäume & Palmen)
            let innerCount = 0;
            for (let i = 0; i < 45; i++) {
                for (let attempt = 0; attempt < 30; attempt++) {
                    const angle = Math.random() * Math.PI * 2;
                    const r = 25 + Math.random() * 95;
                    const tx = Math.cos(angle) * r;
                    const tz = Math.sin(angle) * r;
                    if (isTreePositionSafe(tx, tz)) {
                        this.addStylizedTree(tx, tz, (innerCount % 3 === 0));
                        innerCount++;
                        break;
                    }
                }
            }

            // B. Weitläufiges Küsten- & Hügelpanorama (hinter den Grundstücken am Meer)
            let outerCount = 0;
            for (let i = 0; i < 65; i++) {
                for (let attempt = 0; attempt < 30; attempt++) {
                    const angle = Math.random() * Math.PI * 2;
                    const r = 230 + Math.random() * 115;
                    const tx = Math.cos(angle) * r;
                    const tz = Math.sin(angle) * r;
                    if (isTreePositionSafe(tx, tz)) {
                        this.addStylizedTree(tx, tz, (outerCount % 2 === 0));
                        outerCount++;
                        break;
                    }
                }
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

            // 1. Aerodynamische flache Motorhaube vorn (in Fahrtrichtung -Z!)
            const hoodGeo = new THREE.BoxGeometry(3.0, 0.28, 3.2);
            this.carHoodMat = new THREE.MeshStandardMaterial({
                color: 0xef4444,
                roughness: 0.22,
                metalness: 0.7,
            });
            this.carHoodMesh = new THREE.Mesh(hoodGeo, this.carHoodMat);
            this.carHoodMesh.position.set(0, 0.42, -2.5);
            this.carHoodMesh.castShadow = true;
            this.cockpitRig.add(this.carHoodMesh);

            // 2. Sportlich abgesenktes Armaturenbrett (hochwertige Carbon-Fiber-Webstruktur, blockiert nicht das Sichtfeld!)
            const dashGeo = new THREE.BoxGeometry(3.5, 0.3, 1.1);
            const dashMat = new THREE.MeshStandardMaterial({
                map: this.getCarbonFiberTexture(),
                roughness: 0.65,
                metalness: 0.35,
            });
            const dash = new THREE.Mesh(dashGeo, dashMat);
            dash.position.set(0, 0.52, -0.85);
            this.cockpitRig.add(dash);

            // 3. Digitales Tacho-Kombiinstrument vor dem Fahrer (fotorealistisches digitales OLED-Cockpit mit Speedo & RPM)
            const clusterGeo = new THREE.BoxGeometry(0.72, 0.2, 0.04);
            const clusterMat = new THREE.MeshBasicMaterial({
                map: this.getDashboardDisplayTexture(),
            });
            const cluster = new THREE.Mesh(clusterGeo, clusterMat);
            cluster.position.set(-0.7, 0.78, -0.7);
            cluster.rotation.x = -0.25;
            this.cockpitRig.add(cluster);

            // Schlanke Tacho-Hutze über dem Display (flach integriert)
            const cowlGeo = new THREE.BoxGeometry(0.8, 0.08, 0.25);
            const cowl = new THREE.Mesh(cowlGeo, dashMat);
            cowl.position.set(-0.7, 0.9, -0.78);
            this.cockpitRig.add(cowl);

            // 4. Sportlenkrad (Links auf Fahrerseite)
            const wheelGroup = new THREE.Group();
            wheelGroup.position.set(-0.7, 0.8, -0.4);
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
        disposeHierarchy(node) {
            if (!node) return;
            node.traverse(child => {
                if (child.geometry) {
                    child.geometry.dispose();
                }
                if (child.material) {
                    if (Array.isArray(child.material)) {
                        child.material.forEach(m => {
                            if (m.map) m.map.dispose();
                            m.dispose();
                        });
                    } else {
                        if (child.material.map) child.material.map.dispose();
                        child.material.dispose();
                    }
                }
            });
        }

        renderPlotState(tileData, ownerColor = null, isMortgaged = false, ownerName = null, force = false) {
            const tileIndex = typeof tileData === 'object' ? tileData.index : tileData;
            const group = this.tilePlotGroups[tileIndex];
            if (!group) return;

            const tileType = (typeof tileData === 'object' && tileData.type)
                ? tileData.type
                : this.getTileTypeByIndex(tileIndex);

            const level = (typeof tileData === 'object' && tileData.level !== undefined)
                ? tileData.level
                : 0;

            const tileName = (typeof tileData === 'object' && tileData.name)
                ? tileData.name
                : `Feld ${tileIndex}`;

            // PERFORMANCE & RAM-OPTIMIERUNG:
            // Verhindert das ununterbrochene Neugenerieren von 1.600 3D-Meshes alle 1.5s im Polling-Zyklus!
            if (!this.renderedPlotSignatures) this.renderedPlotSignatures = {};
            const sig = `${ownerColor}_${isMortgaged ? 1 : 0}_${level}_${ownerName || ''}`;
            if (!force && this.renderedPlotSignatures[tileIndex] === sig && group.children.length > 0) {
                return;
            }
            this.renderedPlotSignatures[tileIndex] = sig;

            // Altes Mesh sauber aus GPU- und JS-Heap freigeben
            this.disposeHierarchy(group);
            while (group.children.length > 0) {
                group.remove(group.children[0]);
            }

            if (tileType === 'club') {
                this.renderClubPlot(group, ownerColor, isMortgaged, tileName);
            } else if (tileType === 'beach') {
                this.renderBeachPlot(group, ownerColor, isMortgaged, tileName);
            } else if (tileType === 'street') {
                const groupKey = (typeof tileData === 'object' && tileData.group) ? tileData.group : this.getTileGroupByIndex(tileIndex);
                const price = (typeof tileData === 'object' && tileData.price) ? tileData.price : 200;
                this.renderStreetPlot(group, level, ownerColor, isMortgaged, tileName, groupKey, price, tileIndex, ownerName);
            } else {
                this.renderSpecialPlot(group, tileType, tileName, tileIndex);
            }
        }

        // ---------------------------------------------------------------------
        // TEXTUR-GENERATOREN & PBR-LOADER FÜR THREE.JS
        // ---------------------------------------------------------------------
        loadSeamlessTexture(filePath, isSRGB = true, repeatX = 1, repeatY = 1) {
            const key = `pbr_${filePath}_${repeatX}_${repeatY}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const loader = new THREE.TextureLoader();
            const tex = loader.load(filePath);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            if (repeatX !== 1 || repeatY !== 1) {
                tex.repeat.set(repeatX, repeatY);
            }
            if (isSRGB) {
                tex.colorSpace = THREE.SRGBColorSpace;
            }
            this._texCache[key] = tex;
            return tex;
        }

        getAsphaltTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['asphalt']) return this._texCache['asphalt'];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // Grundton: Authentischer dunkler Asphalt / Tarmac
            ctx.fillStyle = '#1e2229';
            ctx.fillRect(0, 0, 512, 512);

            // Feines Splitt-/Gesteins-Rauschen (helle und dunkle Körner)
            const imgData = ctx.getImageData(0, 0, 512, 512);
            const d = imgData.data;
            for (let i = 0; i < d.length; i += 4) {
                const noise = (Math.random() - 0.5) * 36;
                d[i] = Math.min(255, Math.max(0, d[i] + noise));
                d[i + 1] = Math.min(255, Math.max(0, d[i + 1] + noise));
                d[i + 2] = Math.min(255, Math.max(0, d[i + 2] + noise));
            }
            ctx.putImageData(imgData, 0, 0);

            // Reifenspuren / Reifenabrieb (zwei leicht dunklere Spurrillen in Längsrichtung)
            const tireTrack1 = ctx.createLinearGradient(80, 0, 200, 0);
            tireTrack1.addColorStop(0, 'rgba(15, 17, 21, 0)');
            tireTrack1.addColorStop(0.5, 'rgba(15, 17, 21, 0.28)');
            tireTrack1.addColorStop(1, 'rgba(15, 17, 21, 0)');
            ctx.fillStyle = tireTrack1;
            ctx.fillRect(80, 0, 120, 512);

            const tireTrack2 = ctx.createLinearGradient(312, 0, 432, 0);
            tireTrack2.addColorStop(0, 'rgba(15, 17, 21, 0)');
            tireTrack2.addColorStop(0.5, 'rgba(15, 17, 21, 0.28)');
            tireTrack2.addColorStop(1, 'rgba(15, 17, 21, 0)');
            ctx.fillStyle = tireTrack2;
            ctx.fillRect(312, 0, 120, 512);

            // Subtile Teernaht / Asphalt-Längsfuge
            ctx.strokeStyle = 'rgba(10, 12, 16, 0.35)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.moveTo(256, 0);
            ctx.lineTo(256, 512);
            ctx.stroke();

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['asphalt'] = tex;
            return tex;
        }

        getSidewalkTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['sidewalk']) return this._texCache['sidewalk'];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // Gehweg-Platten aus hellem Naturstein / Betonpflaster (8x8 Platten im Quadrat)
            ctx.fillStyle = '#64748b'; // Fugenfarbe (dunkler Mörtel)
            ctx.fillRect(0, 0, 512, 512);

            const tiles = 8;
            const size = 512 / tiles;
            for (let r = 0; r < tiles; r++) {
                for (let c = 0; c < tiles; c++) {
                    const x = c * size;
                    const y = r * size;
                    const v = Math.floor((Math.random() - 0.5) * 18);
                    const red = 226 + v;
                    const green = 232 + v;
                    const blue = 240 + v;
                    ctx.fillStyle = `rgb(${red}, ${green}, ${blue})`;
                    ctx.fillRect(x + 2, y + 2, size - 4, size - 4);

                    // Subtile Lichtkante oben/links
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.35)';
                    ctx.fillRect(x + 2, y + 2, size - 4, 2);
                    ctx.fillRect(x + 2, y + 2, 2, size - 4);

                    // Subtiler Schatten unten/rechts an Fugenrand
                    ctx.fillStyle = 'rgba(15, 23, 42, 0.12)';
                    ctx.fillRect(x + 2, y + size - 4, size - 4, 2);
                    ctx.fillRect(x + size - 4, y + 2, 2, size - 4);
                }
            }

            // Gesteinskörnung
            const imgData = ctx.getImageData(0, 0, 512, 512);
            const d = imgData.data;
            for (let i = 0; i < d.length; i += 4) {
                const grain = (Math.random() - 0.5) * 16;
                d[i] = Math.min(255, Math.max(0, d[i] + grain));
                d[i + 1] = Math.min(255, Math.max(0, d[i + 1] + grain));
                d[i + 2] = Math.min(255, Math.max(0, d[i + 2] + grain));
            }
            ctx.putImageData(imgData, 0, 0);

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['sidewalk'] = tex;
            return tex;
        }

        getCurbTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['curb']) return this._texCache['curb'];

            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 64;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = '#94a3b8';
            ctx.fillRect(0, 0, 256, 64);

            for (let x = 0; x < 256; x += 32) {
                const shade = Math.floor((Math.random() - 0.5) * 20);
                ctx.fillStyle = `rgb(${155 + shade}, ${168 + shade}, ${185 + shade})`;
                ctx.fillRect(x + 1, 0, 30, 64);

                // Lichtkante oben
                ctx.fillStyle = 'rgba(255, 255, 255, 0.4)';
                ctx.fillRect(x + 1, 0, 30, 4);

                // Fuge
                ctx.fillStyle = '#475569';
                ctx.fillRect(x, 0, 2, 64);
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['curb'] = tex;
            return tex;
        }

        getGrassTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['grass']) return this._texCache['grass'];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // Satter grüner Wiesengrund
            ctx.fillStyle = '#15803d';
            ctx.fillRect(0, 0, 512, 512);

            // Organische Flecken (Multi-Frequenz-Nuancen)
            const colors = ['#16a34a', '#14532d', '#15803d', '#166534', '#1e3a1e', '#22c55e'];
            for (let i = 0; i < 400; i++) {
                const x = Math.random() * 512;
                const y = Math.random() * 512;
                const r = 8 + Math.random() * 28;
                ctx.fillStyle = colors[Math.floor(Math.random() * colors.length)];
                ctx.globalAlpha = 0.25;
                ctx.beginPath();
                ctx.arc(x, y, r, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1.0;

            // Feine Grashalm-Sprenkel
            for (let i = 0; i < 1500; i++) {
                const x = Math.random() * 512;
                const y = Math.random() * 512;
                ctx.fillStyle = Math.random() > 0.4 ? '#22c55e' : '#14532d';
                ctx.fillRect(x, y, 1.5, 3 + Math.random() * 3);
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(50, 50);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['grass'] = tex;
            return tex;
        }

        getOceanTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['ocean']) return this._texCache['ocean'];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // Tiefes tropisches Türkisblau
            const grad = ctx.createLinearGradient(0, 0, 512, 512);
            grad.addColorStop(0, '#0369a1');
            grad.addColorStop(0.5, '#0284c7');
            grad.addColorStop(1, '#0ea5e9');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, 512, 512);

            // Kaustik-Netzwerk / Wellenkräuseln
            ctx.strokeStyle = 'rgba(255, 255, 255, 0.16)';
            ctx.lineWidth = 2.5;
            for (let i = 0; i < 40; i++) {
                ctx.beginPath();
                const y = i * 14;
                for (let x = 0; x <= 512; x += 16) {
                    const waveY = y + Math.sin((x + i * 25) * 0.05) * 6 + Math.cos(x * 0.08) * 4;
                    if (x === 0) ctx.moveTo(x, waveY);
                    else ctx.lineTo(x, waveY);
                }
                ctx.stroke();
            }

            // Gischt- & Schaumakzente
            ctx.fillStyle = 'rgba(255, 255, 255, 0.25)';
            for (let j = 0; j < 80; j++) {
                ctx.beginPath();
                ctx.arc(Math.random() * 512, Math.random() * 512, 1 + Math.random() * 2.5, 0, Math.PI * 2);
                ctx.fill();
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(70, 70);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['ocean'] = tex;
            return tex;
        }

        getModernFacadeTexture(colorHex = '#f8fafc') {
            const key = `facade_${colorHex}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = colorHex;
            ctx.fillRect(0, 0, 512, 512);

            // Horizontale Geschoss-Fugen
            ctx.strokeStyle = 'rgba(15, 23, 42, 0.12)';
            ctx.lineWidth = 3;
            for (let y = 128; y < 512; y += 128) {
                ctx.beginPath();
                ctx.moveTo(0, y);
                ctx.lineTo(512, y);
                ctx.stroke();
            }

            // Große moderne Panorama-Fensterreihen
            for (let row = 0; row < 3; row++) {
                const py = 35 + row * 135;
                for (let col = 0; col < 3; col++) {
                    const px = 30 + col * 155;
                    const winGrad = ctx.createLinearGradient(px, py, px + 130, py + 90);
                    winGrad.addColorStop(0, '#0a1020');
                    winGrad.addColorStop(0.5, '#1e293b');
                    winGrad.addColorStop(1, '#0f172a');
                    ctx.fillStyle = winGrad;
                    ctx.fillRect(px, py, 130, 90);

                    ctx.strokeStyle = '#020617';
                    ctx.lineWidth = 3;
                    ctx.strokeRect(px, py, 130, 90);

                    // Innere Beleuchtungs-Atmosphäre
                    if ((row + col) % 2 === 0) {
                        ctx.fillStyle = 'rgba(254, 240, 138, 0.22)';
                        ctx.fillRect(px + 10, py + 40, 110, 42);
                    }

                    // Schräge Lichtreflexion über das Glas
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.12)';
                    ctx.beginPath();
                    ctx.moveTo(px + 15, py);
                    ctx.lineTo(px + 45, py);
                    ctx.lineTo(px + 20, py + 90);
                    ctx.lineTo(px, py + 90);
                    ctx.closePath();
                    ctx.fill();
                }
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getRoofTilesTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['roof_tiles']) return this._texCache['roof_tiles'];

            const canvas = document.createElement('canvas');
            canvas.width = 256;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = '#1e293b';
            ctx.fillRect(0, 0, 256, 256);

            const rowH = 16;
            const tileW = 32;
            for (let y = 0; y < 256; y += rowH) {
                const shift = ((y / rowH) % 2) * (tileW / 2);
                for (let x = -tileW; x < 256 + tileW; x += tileW) {
                    const tx = x + shift;
                    ctx.fillStyle = '#334155';
                    ctx.fillRect(tx + 1, y + 1, tileW - 2, rowH - 2);

                    ctx.fillStyle = '#0f172a';
                    ctx.fillRect(tx + 1, y + rowH - 3, tileW - 2, 2);

                    ctx.fillStyle = 'rgba(255, 255, 255, 0.15)';
                    ctx.fillRect(tx + 1, y + 1, tileW - 2, 1);
                }
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(3, 3);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['roof_tiles'] = tex;
            return tex;
        }

        getCarbonFiberTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['carbon']) return this._texCache['carbon'];

            const canvas = document.createElement('canvas');
            canvas.width = 64;
            canvas.height = 64;
            const ctx = canvas.getContext('2d');

            ctx.fillStyle = '#18181b';
            ctx.fillRect(0, 0, 64, 64);

            const s = 16;
            for (let y = 0; y < 64; y += s) {
                for (let x = 0; x < 64; x += s) {
                    const isDiag = ((x / s) + (y / s)) % 2 === 0;
                    const grad = ctx.createLinearGradient(x, y, x + s, y + s);
                    if (isDiag) {
                        grad.addColorStop(0, '#27272a');
                        grad.addColorStop(0.5, '#3f3f46');
                        grad.addColorStop(1, '#18181b');
                    } else {
                        grad.addColorStop(0, '#18181b');
                        grad.addColorStop(0.5, '#09090b');
                        grad.addColorStop(1, '#27272a');
                    }
                    ctx.fillStyle = grad;
                    ctx.fillRect(x, y, s, s);
                }
            }

            const tex = new THREE.CanvasTexture(canvas);
            tex.wrapS = THREE.RepeatWrapping;
            tex.wrapT = THREE.RepeatWrapping;
            tex.repeat.set(16, 6);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['carbon'] = tex;
            return tex;
        }

        getDashboardDisplayTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['cluster']) return this._texCache['cluster'];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 140;
            const ctx = canvas.getContext('2d');

            // OLED Tiefschwarz
            ctx.fillStyle = '#060913';
            ctx.fillRect(0, 0, 512, 140);

            // Cyan Rahmen
            ctx.strokeStyle = '#0284c7';
            ctx.lineWidth = 3;
            ctx.strokeRect(2, 2, 508, 136);

            // LINKS: DREHZAHL-BOGEN
            ctx.save();
            ctx.lineWidth = 8;
            ctx.strokeStyle = '#1e293b';
            ctx.beginPath();
            ctx.arc(95, 75, 48, Math.PI * 0.75, Math.PI * 2.25);
            ctx.stroke();

            ctx.strokeStyle = '#00f5ff';
            ctx.shadowColor = '#00f5ff';
            ctx.shadowBlur = 12;
            ctx.beginPath();
            ctx.arc(95, 75, 48, Math.PI * 0.75, Math.PI * 1.7);
            ctx.stroke();

            ctx.strokeStyle = '#ef4444';
            ctx.shadowColor = '#ef4444';
            ctx.beginPath();
            ctx.arc(95, 75, 48, Math.PI * 1.7, Math.PI * 2.15);
            ctx.stroke();
            ctx.restore();

            ctx.fillStyle = '#ef4444';
            ctx.font = 'bold 13px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('SPORT', 95, 80);

            // MITTE: SPEEDOMETER & GANG
            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.fillStyle = '#38bdf8';
            ctx.font = 'bold 16px sans-serif';
            ctx.fillText('D 5', 256, 32);

            ctx.shadowColor = '#00f5ff';
            ctx.shadowBlur = 14;
            ctx.fillStyle = '#f8fafc';
            ctx.font = '900 58px "Arial Black", sans-serif';
            ctx.fillText('124', 256, 75);

            ctx.shadowBlur = 0;
            ctx.fillStyle = '#64748b';
            ctx.font = 'bold 13px sans-serif';
            ctx.fillText('KM / H', 256, 115);
            ctx.restore();

            // RECHTS: REICHWEITE & AKKU
            ctx.fillStyle = '#10b981';
            ctx.font = 'bold 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.fillText('BATTERY', 420, 38);

            ctx.fillStyle = '#1e293b';
            ctx.fillRect(360, 52, 120, 14);
            ctx.fillStyle = '#10b981';
            ctx.shadowColor = '#10b981';
            ctx.shadowBlur = 8;
            ctx.fillRect(362, 54, 98, 10);
            ctx.shadowBlur = 0;

            ctx.fillStyle = '#f8fafc';
            ctx.font = 'bold 22px sans-serif';
            ctx.fillText('420 KM', 420, 95);
            ctx.fillStyle = '#64748b';
            ctx.font = '11px sans-serif';
            ctx.fillText('RANGE', 420, 116);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['cluster'] = tex;
            return tex;
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

        getAutokinoSignTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['autokino_sign']) return this._texCache['autokino_sign'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // 1. OBERES SCHILD: "AUTOKINO" (Creme-Kasten mit Glühbirnen-Rahmen & roten 3D-Buchstaben wie im Foto!)
            ctx.fillStyle = '#fef3c7';
            ctx.fillRect(40, 30, 944, 230);
            ctx.strokeStyle = '#0284c7';
            ctx.lineWidth = 14;
            ctx.strokeRect(40, 30, 944, 230);

            // Glühbirnen-Kette um den Rahmen
            for (let bx = 55; bx <= 970; bx += 32) {
                const drawBulb = (x, y) => {
                    ctx.fillStyle = '#fef08a';
                    ctx.beginPath(); ctx.arc(x, y, 7, 0, Math.PI * 2); ctx.fill();
                    ctx.fillStyle = '#f59e0b';
                    ctx.beginPath(); ctx.arc(x, y, 4, 0, Math.PI * 2); ctx.fill();
                };
                drawBulb(bx, 40);
                drawBulb(bx, 250);
            }
            for (let by = 55; by <= 235; by += 30) {
                const drawBulb = (x, y) => {
                    ctx.fillStyle = '#fef08a';
                    ctx.beginPath(); ctx.arc(x, y, 7, 0, Math.PI * 2); ctx.fill();
                    ctx.fillStyle = '#f59e0b';
                    ctx.beginPath(); ctx.arc(x, y, 4, 0, Math.PI * 2); ctx.fill();
                };
                drawBulb(50, by);
                drawBulb(974, by);
            }

            // Schriftzug "AUTOKINO" in tiefroten 3D-Relief-Buchstaben
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 130px "Arial Black", "Impact", sans-serif';

            ctx.fillStyle = '#7f1d1d';
            ctx.fillText('AUTOKINO', 516, 150);
            ctx.strokeStyle = '#450a0a';
            ctx.lineWidth = 14;
            ctx.strokeText('AUTOKINO', 512, 145);
            ctx.fillStyle = '#dc2626';
            ctx.fillText('AUTOKINO', 512, 145);
            ctx.fillStyle = '#fca5a5';
            ctx.font = '900 120px "Arial Black", "Impact", sans-serif';
            ctx.fillText('AUTOKINO', 512, 143);

            // 2. UNTERES SCHILD: "& Sunset Point" (Türkis-cremefarbene geschwungene Plakette)
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(80, 290, 864, 180);
            ctx.strokeStyle = '#0f766e';
            ctx.lineWidth = 10;
            ctx.strokeRect(80, 290, 864, 180);

            // Elegante Schreibschrift "& Sunset Point"
            ctx.font = 'italic 700 85px "Brush Script MT", "Segoe Script", "Dancing Script", cursive, sans-serif';
            ctx.fillStyle = '#0f766e';
            ctx.fillText('& Sunset Point', 512, 380);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['autokino_sign'] = tex;
            return tex;
        }

        getAutokinoScreenTexture() {
            if (!this._texCache) this._texCache = {};
            if (this._texCache['autokino_screen']) return this._texCache['autokino_screen'];
            const canvas = document.createElement('canvas');
            canvas.width = 1024;
            canvas.height = 512;
            const ctx = canvas.getContext('2d');

            // Film-Leinwand: Sonnenuntergangs-Road-Movie Szene!
            const grad = ctx.createLinearGradient(0, 0, 0, 512);
            grad.addColorStop(0, '#f97316');
            grad.addColorStop(0.4, '#ec4899');
            grad.addColorStop(0.7, '#8b5cf6');
            grad.addColorStop(1, '#1e1b4b');
            ctx.fillStyle = grad;
            ctx.fillRect(0, 0, 1024, 512);

            // Riesige goldene Sonne
            ctx.fillStyle = '#fef08a';
            ctx.beginPath(); ctx.arc(512, 320, 140, 0, Math.PI * 2); ctx.fill();

            // Silhouette eines Autos auf dem Highway
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(0, 440, 1024, 72);
            ctx.fillRect(400, 410, 224, 35);
            ctx.fillRect(440, 385, 140, 28);

            // Retro Filmtitel oben
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 52px "Arial Black", sans-serif';
            ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
            ctx.fillText('NOW SHOWING: UNDER THE HOOD', 512, 70);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache['autokino_screen'] = tex;
            return tex;
        }

        getTileGroupByIndex(i) {
            const groups = {
                1: 'docks', 3: 'docks',
                6: 'suburb', 8: 'suburb', 9: 'suburb',
                11: 'arts', 13: 'arts', 14: 'arts',
                16: 'promenade', 18: 'promenade', 19: 'promenade',
                21: 'downtown', 23: 'downtown', 24: 'downtown',
                26: 'marina', 27: 'marina', 29: 'marina',
                31: 'heights', 32: 'heights', 34: 'heights',
                37: 'millionaires', 39: 'millionaires',
            };
            return groups[i] || 'docks';
        }

        getGroupColor(groupKey) {
            const map = {
                docks: '#8B5A2B',        // Braun / Rost
                suburb: '#38BDF8',       // Hellblau / Sky Blue
                arts: '#EC4899',         // Pink / Magenta
                promenade: '#F97316',    // Orange
                downtown: '#EF4444',     // Rot
                marina: '#EAB308',       // Gelb / Gold
                heights: '#22C55E',      // Grün
                millionaires: '#3B82F6', // Dunkelblau / Royal
            };
            return map[groupKey] || '#64748b';
        }

        getGroupName(groupKey) {
            const map = {
                docks: 'Docks & Industrie',
                suburb: 'Vorstadt & Diners',
                arts: 'Künstlerviertel',
                promenade: 'Flaniermeile',
                downtown: 'Downtown & Skyline',
                marina: 'Yachthafen & Marina',
                heights: 'Panorama-Höhen',
                millionaires: 'Millionaires’ Hill',
            };
            return map[groupKey] || 'Kategorie';
        }

        getForSaleSignTexture(tileName, groupName, groupColorHex, price) {
            const key = `forsale_${tileName}_${groupColorHex}_${price}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');

            // Hintergrund: Weißes wettergegerbtes Blechschild mit Rosträndern (wie im Foto 1!)
            ctx.fillStyle = '#f1f5f9';
            ctx.fillRect(0, 0, 512, 256);

            ctx.fillStyle = 'rgba(120, 53, 15, 0.12)';
            ctx.fillRect(4, 4, 25, 45);
            ctx.fillRect(480, 210, 28, 42);

            ctx.strokeStyle = '#94a3b8';
            ctx.lineWidth = 6;
            ctx.strokeRect(4, 4, 504, 248);

            // OBERER KATEGORIE-FARB-BALKEN (Deutliche Kennzeichnung der Straßengruppe!)
            ctx.fillStyle = groupColorHex;
            ctx.fillRect(8, 8, 496, 54);

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 24px "Arial Black", sans-serif';
            ctx.fillStyle = '#ffffff';
            ctx.shadowColor = 'rgba(0, 0, 0, 0.6)';
            ctx.shadowBlur = 6;
            ctx.fillText(`${groupName.toUpperCase()} • ${price} $`, 256, 35);
            ctx.shadowBlur = 0;

            // HAUPTZEILE "ZU VERKAUFEN" in fetter markanter schwarzer Schrift
            ctx.font = '900 58px "Arial Black", "Impact", sans-serif';
            ctx.fillStyle = '#0f172a';
            ctx.fillText('ZU VERKAUFEN', 256, 125);

            // Straßenname & Details
            ctx.font = '700 22px sans-serif';
            ctx.fillStyle = '#334155';
            ctx.fillText(`Objekt: ${tileName}`, 256, 175);

            // Makler- / Privatverkaufs-Hinweis
            ctx.font = 'italic 16px sans-serif';
            ctx.fillStyle = '#64748b';
            ctx.fillText('Privatverkauf • Bauparzelle der Stadt • 555-HOOD', 256, 215);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
            return tex;
        }

        getOwnedGardenSignTexture(tileName, groupName, groupColorHex, ownerName, ownerColorHex) {
            const key = `owned_garden_${tileName}_${groupColorHex}_${ownerName}`;
            if (!this._texCache) this._texCache = {};
            if (this._texCache[key]) return this._texCache[key];

            const canvas = document.createElement('canvas');
            canvas.width = 512;
            canvas.height = 256;
            const ctx = canvas.getContext('2d');

            // Hochwertige Emaillierte Tafel
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(0, 0, 512, 256);

            // KATEGORIE-FARB-RAHMEN
            ctx.strokeStyle = groupColorHex;
            ctx.lineWidth = 12;
            ctx.strokeRect(6, 6, 500, 244);

            // Oberer Streifen mit Gruppenfarbe
            ctx.fillStyle = groupColorHex;
            ctx.fillRect(12, 12, 488, 48);

            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.font = '900 22px "Arial Black", sans-serif';
            ctx.fillStyle = '#ffffff';
            ctx.fillText(groupName.toUpperCase(), 256, 36);

            // Straßenname
            ctx.font = '900 44px sans-serif';
            ctx.fillStyle = '#f8fafc';
            ctx.fillText(tileName, 256, 115);

            // Eigentümer-Badge in Spielerfarbe
            ctx.fillStyle = ownerColorHex || '#22c55e';
            ctx.beginPath();
            ctx.roundRect(50, 165, 412, 60, 10);
            ctx.fill();

            ctx.font = '900 26px sans-serif';
            ctx.fillStyle = '#ffffff';
            ctx.shadowColor = 'rgba(0,0,0,0.5)';
            ctx.shadowBlur = 4;
            ctx.fillText(`Eigentum: ${ownerName || 'Danny'}`, 256, 195);

            const tex = new THREE.CanvasTexture(canvas);
            tex.colorSpace = THREE.SRGBColorSpace;
            this._texCache[key] = tex;
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

        // --- C. STRASSEN (EXAKTE UMSETZUNG DER BEIDEN REFERENZBILDER DES USERS!) ---
        renderStreetPlot(group, level, ownerColor, isMortgaged, tileName, groupKey = 'docks', price = 200, tileIndex = 1, ownerName = null) {
            const isOwned = !!ownerColor;
            const groupColorHex = this.getGroupColor(groupKey);
            const groupName = this.getGroupName(groupKey);
            const ownerHex = ownerColor || '#3b82f6';

            // Gehweg vor dem Grundstück mit integriertem KATEGORIE-FARB-BORDSTEIN!
            // So sieht der Fahrer schon auf der Straße sofort die zusammenhängenden Farbabschnitte!
            const curbStrip = new THREE.Mesh(
                new THREE.BoxGeometry(0.35, 0.12, 18),
                new THREE.MeshBasicMaterial({ color: new THREE.Color(groupColorHex) })
            );
            curbStrip.position.set(-5.6, 0.44, 0);
            group.add(curbStrip);

            if (!isOwned) {
                // =============================================================
                // 1. UNBEBAUT / ZU VERKAUFEN (EXAKT WIE IM REFERENZBILD 1!)
                // =============================================================
                // Verwilderter Boden mit Erdflecken, hohem Unkraut und Gestrüpp
                const wildGroundMat = new THREE.MeshStandardMaterial({ map: this.getGrassTexture(), roughness: 0.95 });
                const plot = new THREE.Mesh(new THREE.BoxGeometry(20, 0.35, 18), wildGroundMat);
                plot.position.set(0, 0.18, 0);
                plot.receiveShadow = true;
                group.add(plot);

                // Erdflecken
                const dirtMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.95 });
                const d1 = new THREE.Mesh(new THREE.BoxGeometry(8, 0.04, 7), dirtMat);
                d1.position.set(-1, 0.37, 2);
                group.add(d1);

                // Schiefer, alter Maschendrahtzaun entlang der Grundstücksgrenze (wie im Foto!)
                const woodPostMat = new THREE.MeshStandardMaterial({ color: 0x713f12, roughness: 0.9 });
                const fenceWireMat = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.8 });

                for (let z = -8; z <= 8; z += 2.6) {
                    // Schiefe Holzpfosten
                    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.1, 2.2, 6), woodPostMat);
                    post.position.set(-6.5, 1.1, z);
                    post.rotation.z = (Math.sin(z * 1.5)) * 0.12;
                    post.rotation.x = (Math.cos(z * 1.8)) * 0.1;
                    group.add(post);

                    // Maschendraht-Feld
                    if (z < 8) {
                        const wireGeo = new THREE.BoxGeometry(0.04, 1.4, 2.6);
                        const wire = new THREE.Mesh(wireGeo, fenceWireMat);
                        wire.position.set(-6.5, 0.9, z + 1.3);
                        wire.rotation.z = (Math.sin(z)) * 0.08;
                        group.add(wire);
                    }
                }

                // =========================================================
                // DAS ORIGINALE "ZU VERKAUFEN"-SCHILD AM ZAUN (MIT KATEGORIE-FARBE!)
                // =========================================================
                const signMat = new THREE.MeshStandardMaterial({
                    map: this.getForSaleSignTexture(tileName, groupName, groupColorHex, price),
                    roughness: 0.5
                });
                const forSaleSign = new THREE.Mesh(new THREE.BoxGeometry(0.12, 1.4, 2.8), signMat);
                forSaleSign.position.set(-6.3, 1.3, -1.5);
                forSaleSign.rotation.y = 0.05;
                forSaleSign.rotation.z = -0.06;
                group.add(forSaleSign);

                // Geröllhaufen / Schotterberge (wie im Foto!)
                const rockMat = new THREE.MeshStandardMaterial({ color: 0x64748b, roughness: 0.9 });
                for (let r = 0; r < 7; r++) {
                    const stone = new THREE.Mesh(new THREE.DodecahedronGeometry(0.4 + (r % 3) * 0.2, 0), rockMat);
                    stone.position.set(-3.5 + (r % 3) * 0.6, 0.5 + Math.floor(r / 3) * 0.3, -3.5 + (r % 2) * 0.8);
                    stone.rotation.set(r * 0.5, r * 0.7, 0);
                    group.add(stone);
                }

                // Altreifen-Stapel im Unkraut (wie im Foto!)
                const tireMat = new THREE.MeshStandardMaterial({ color: 0x18181b, roughness: 0.95 });
                for (let t = 0; t < 3; t++) {
                    const tire = new THREE.Mesh(new THREE.CylinderGeometry(0.42, 0.42, 0.28, 12), tireMat);
                    tire.position.set(-2.0, 0.45 + t * 0.26, 1.2);
                    tire.rotation.x = Math.PI / 2 + (t === 2 ? 0.3 : 0);
                    group.add(tire);
                }

                // Wildes Gestrüpp & Disteln
                const bushMat = new THREE.MeshStandardMaterial({ color: 0x3f6212, roughness: 0.9 });
                for (let b = 0; b < 6; b++) {
                    const bush = new THREE.Mesh(new THREE.SphereGeometry(0.7 + (b % 3) * 0.3, 6, 6), bushMat);
                    bush.position.set(2 + (b % 2) * 2, 0.8, -4 + b * 1.6);
                    bush.scale.set(1.2, 0.8, 1.1);
                    group.add(bush);
                }

                // Verwilderter Baum im Hintergrund
                const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.35, 4.5, 8), woodPostMat);
                trunk.position.set(4, 2.4, 2);
                group.add(trunk);
                const crown = new THREE.Mesh(new THREE.SphereGeometry(2.6, 8, 8), bushMat);
                crown.position.set(4, 5.2, 2);
                group.add(crown);

            } else {
                // =============================================================
                // 2. GEKAUFT: GEPFLEGTER ZIERRASEN & METALLZAUN (BILD 2)
                // =============================================================
                // Perfekt gemähter, satter englischer Rasen
                const lawnMat = new THREE.MeshStandardMaterial({ map: this.getGrassTexture(), roughness: 0.75 });
                const plot = new THREE.Mesh(new THREE.BoxGeometry(20, 0.35, 18), lawnMat);
                plot.position.set(0, 0.18, 0);
                plot.receiveShadow = true;
                group.add(plot);

                // Mulch-Beete entlang des Zauns mit Rindenmulch
                const mulchMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.95 });
                const bed1 = new THREE.Mesh(new THREE.BoxGeometry(2.5, 0.04, 7.0), mulchMat);
                bed1.position.set(-5.0, 0.38, -4.5);
                group.add(bed1);
                const bed2 = new THREE.Mesh(new THREE.BoxGeometry(2.5, 0.04, 7.0), mulchMat);
                bed2.position.set(-5.0, 0.38, 4.5);
                group.add(bed2);

                // Bunte Rosenbeete & Lavendel
                const roseCols = [0xf43f5e, 0xec4899, 0xa855f7, 0xf8fafc];
                for (let fl = 0; fl < 16; fl++) {
                    const fMat = new THREE.MeshBasicMaterial({ color: roseCols[fl % roseCols.length] });
                    const flower = new THREE.Mesh(new THREE.SphereGeometry(0.16, 6, 6), fMat);
                    const zSide = fl < 8 ? -4.5 + (fl % 4) * 1.4 : 2.5 + (fl % 4) * 1.4;
                    flower.position.set(-5.0 + (fl % 2) * 0.6, 0.45, zSide);
                    group.add(flower);
                }

                // Moderner moosgrüner Doppelstabmattenzaun (wie im Foto!)
                const fenceMat = new THREE.MeshStandardMaterial({ color: 0x14532d, metalness: 0.5, roughness: 0.4 });
                const capMat = new THREE.MeshBasicMaterial({ color: new THREE.Color(groupColorHex) });

                // Linker Zaunflügel
                const fLeft = new THREE.Mesh(new THREE.BoxGeometry(0.08, 1.3, 7.0), fenceMat);
                fLeft.position.set(-6.5, 1.0, -4.5);
                group.add(fLeft);

                // Rechter Zaunflügel
                const fRight = new THREE.Mesh(new THREE.BoxGeometry(0.08, 1.3, 7.0), fenceMat);
                fRight.position.set(-6.5, 1.0, 4.5);
                group.add(fRight);

                // Zaunpfosten mit Kategorie-Farb-Kappen!
                [-8.0, -4.5, -1.2, 1.2, 4.5, 8.0].forEach(pz => {
                    const post = new THREE.Mesh(new THREE.BoxGeometry(0.12, 1.5, 0.12), fenceMat);
                    post.position.set(-6.5, 1.05, pz);
                    group.add(post);

                    // Pfostenkappe in KATEGORIE-FARBE
                    const cap = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.08, 0.16), capMat);
                    cap.position.set(-6.5, 1.82, pz);
                    group.add(cap);
                });

                // Gartentor in der Mitte (2.4m Durchgang)
                const gate = new THREE.Mesh(new THREE.BoxGeometry(0.08, 1.2, 2.2), fenceMat);
                gate.position.set(-6.5, 0.95, 0);
                group.add(gate);

                // Gepflasterter Gartenweg durch das Tor
                const pathMat = new THREE.MeshStandardMaterial({ map: this.getSidewalkTexture(), roughness: 0.8 });
                const gardenPath = new THREE.Mesh(new THREE.BoxGeometry(8, 0.04, 2.2), pathMat);
                gardenPath.position.set(-2.5, 0.38, 0);
                group.add(gardenPath);

                // Eigentümer-Schild am Tor
                const signMat = new THREE.MeshStandardMaterial({
                    map: this.getOwnedGardenSignTexture(tileName, groupName, groupColorHex, ownerName, ownerHex),
                    roughness: 0.3
                });
                const gateSign = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.8, 1.6), signMat);
                gateSign.position.set(-6.45, 1.5, 1.4);
                group.add(gateSign);

                // BESITZER-FLAGGE am Eingang in SPIELERFARBE!
                const flagPole = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 4.5, 8), new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.8 }));
                flagPole.position.set(-6.4, 2.4, -1.4);
                group.add(flagPole);

                const ownerFlag = new THREE.Mesh(new THREE.BoxGeometry(0.04, 0.9, 1.4), new THREE.MeshStandardMaterial({ color: ownerHex, roughness: 0.4 }));
                ownerFlag.position.set(-6.4, 4.1, -2.1);
                group.add(ownerFlag);

                // Gepflegte Solitärbäume & Formgehölze
                const tidyTrunk = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.3, 3.8, 8), new THREE.MeshStandardMaterial({ color: 0x573a08 }));
                tidyTrunk.position.set(3.5, 2.0, 3.5);
                group.add(tidyTrunk);
                const tidyCrown = new THREE.Mesh(new THREE.SphereGeometry(2.2, 10, 10), new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.8 }));
                tidyCrown.position.set(3.5, 4.5, 3.5);
                group.add(tidyCrown);

                // =============================================================
                // 3. ARCHITEKTONISCHE EVOLUTION (Level 1 bis 5)
                // =============================================================
                if (level === 1) {
                    // --- LEVEL 1: ERSCHLOSSENES GARTENGRUNDSTÜCK MIT HOLZHÜTTE / BLOCKHAUS ---
                    const cabin = new THREE.Group();
                    cabin.position.set(3.2, 0, -1.8);

                    // Blockhaus-Korpus aus rustikalem Holz
                    const walls = new THREE.Mesh(
                        new THREE.BoxGeometry(4.2, 2.6, 3.6),
                        new THREE.MeshStandardMaterial({ color: 0x854d0e, roughness: 0.9 })
                    );
                    walls.position.set(0, 1.3, 0);
                    cabin.add(walls);

                    // Schräges Satteldach aus Holzschindeln
                    const roof = new THREE.Mesh(
                        new THREE.BoxGeometry(4.6, 0.45, 4.2),
                        new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.7 })
                    );
                    roof.position.set(0, 2.7, 0);
                    cabin.add(roof);

                    // Holzveranda & Vordach mit Pfosten
                    const porch = new THREE.Mesh(
                        new THREE.BoxGeometry(1.6, 0.15, 2.4),
                        new THREE.MeshStandardMaterial({ color: 0x713f12, roughness: 0.8 })
                    );
                    porch.position.set(-2.5, 0.2, 0);
                    cabin.add(porch);

                    [-0.9, 0.9].forEach(pz => {
                        const post = new THREE.Mesh(
                            new THREE.CylinderGeometry(0.08, 0.08, 2.0, 6),
                            new THREE.MeshStandardMaterial({ color: 0x713f12 })
                        );
                        post.position.set(-3.1, 1.1, pz);
                        cabin.add(post);
                    });

                    // Trittsteine zum Tor
                    [-4.5, -3.2, -1.8, -0.6].forEach(px => {
                        const step = new THREE.Mesh(
                            new THREE.CylinderGeometry(0.35, 0.38, 0.08, 8),
                            new THREE.MeshStandardMaterial({ color: 0x94a3b8, roughness: 0.9 })
                        );
                        step.position.set(px, 0.15, 0.2);
                        group.add(step);
                    });

                    group.add(cabin);

                } else if (level === 2) {
                    // --- LEVEL 2: MODERNES EINFAMILIENHAUS / BUNGALOW ---
                    const house = new THREE.Group();
                    house.position.set(3.2, 0, 0);

                    // Heller Massivbau mit moderner Fassadentextur
                    const body = new THREE.Mesh(
                        new THREE.BoxGeometry(6.8, 3.8, 5.4),
                        new THREE.MeshStandardMaterial({ map: this.getModernFacadeTexture('#f8fafc'), roughness: 0.35 })
                    );
                    body.position.set(0, 1.9, 0);
                    house.add(body);

                    // Anthrazitfarbenes Schieferdach mit Ziegeltextur
                    const roof = new THREE.Mesh(
                        new THREE.BoxGeometry(7.4, 0.55, 6.0),
                        new THREE.MeshStandardMaterial({ map: this.getRoofTilesTexture(), roughness: 0.4 })
                    );
                    roof.position.set(0, 4.0, 0);
                    house.add(roof);

                    // Großes Panorama-Fenster zur Straße
                    const win = new THREE.Mesh(
                        new THREE.BoxGeometry(0.1, 2.0, 2.8),
                        new THREE.MeshStandardMaterial({ color: 0x38bdf8, metalness: 0.7, roughness: 0.1 })
                    );
                    win.position.set(-3.45, 2.0, 0.8);
                    house.add(win);

                    // Eingangstür mit Überdachung in Spielerfarbe
                    const doorRoof = new THREE.Mesh(
                        new THREE.BoxGeometry(1.6, 0.15, 1.8),
                        new THREE.MeshStandardMaterial({ color: ownerHex })
                    );
                    doorRoof.position.set(-3.8, 2.8, -1.5);
                    house.add(doorRoof);

                    // Gepflasterter Gartenweg
                    const path = new THREE.Mesh(
                        new THREE.BoxGeometry(3.5, 0.08, 1.4),
                        new THREE.MeshStandardMaterial({ map: this.getSidewalkTexture(), roughness: 0.8 })
                    );
                    path.position.set(-4.5, 0.15, 0);
                    group.add(path);

                    group.add(house);

                } else if (level === 3) {
                    // --- LEVEL 3: GEHOBENE STADTVILLA MIT 2 VOLLGESCHOSSEN ---
                    const villa = new THREE.Group();
                    villa.position.set(3.5, 0, 0);

                    // Erdgeschoss mit Fassadentextur
                    const groundFloor = new THREE.Mesh(
                        new THREE.BoxGeometry(8.2, 3.4, 7.2),
                        new THREE.MeshStandardMaterial({ map: this.getModernFacadeTexture('#f1f5f9'), roughness: 0.3 })
                    );
                    groundFloor.position.set(0, 1.7, 0);
                    villa.add(groundFloor);

                    // Obergeschoss mit Fassadentextur
                    const upperFloor = new THREE.Mesh(
                        new THREE.BoxGeometry(7.4, 3.0, 6.4),
                        new THREE.MeshStandardMaterial({ map: this.getModernFacadeTexture('#ffffff'), roughness: 0.25 })
                    );
                    upperFloor.position.set(0.3, 4.9, 0);
                    villa.add(upperFloor);

                    // Akzent-Dachabschluss in Spielerfarbe
                    const attika = new THREE.Mesh(
                        new THREE.BoxGeometry(7.8, 0.4, 6.8),
                        new THREE.MeshStandardMaterial({ color: ownerHex, roughness: 0.3 })
                    );
                    attika.position.set(0.3, 6.5, 0);
                    villa.add(attika);

                    // Großer Balkon mit Glasgeländer im OG
                    const balcony = new THREE.Mesh(
                        new THREE.BoxGeometry(2.0, 0.2, 3.8),
                        new THREE.MeshStandardMaterial({ color: 0xe2e8f0 })
                    );
                    balcony.position.set(-3.7, 3.4, 0);
                    villa.add(balcony);

                    const glassRailing = new THREE.Mesh(
                        new THREE.BoxGeometry(0.08, 0.9, 3.8),
                        new THREE.MeshStandardMaterial({ color: 0x38bdf8, transparent: true, opacity: 0.7, roughness: 0.1 })
                    );
                    glassRailing.position.set(-4.7, 3.9, 0);
                    villa.add(glassRailing);

                    // Säulen-Portal am Eingang
                    [-1.2, 1.2].forEach(pz => {
                        const col = new THREE.Mesh(
                            new THREE.CylinderGeometry(0.18, 0.18, 3.4, 12),
                            new THREE.MeshStandardMaterial({ color: 0xffffff })
                        );
                        col.position.set(-4.2, 1.7, pz);
                        villa.add(col);
                    });

                    group.add(villa);

                } else if (level === 4) {
                    // --- LEVEL 4: LUXUS-DESIGNER-RESIDENZ MIT POOL ---
                    const luxury = new THREE.Group();
                    luxury.position.set(3.5, 0, 0);

                    // Weißer Designer-Kubus mit Fassadentextur
                    const cube1 = new THREE.Mesh(
                        new THREE.BoxGeometry(8.5, 6.0, 6.5),
                        new THREE.MeshStandardMaterial({ map: this.getModernFacadeTexture('#ffffff'), roughness: 0.2 })
                    );
                    cube1.position.set(0, 3.0, 1.5);
                    luxury.add(cube1);

                    // Querriegel mit Holz-/Anthrazit-Lamellen
                    const cube2 = new THREE.Mesh(
                        new THREE.BoxGeometry(6.8, 3.0, 8.2),
                        new THREE.MeshStandardMaterial({ color: 0x0f172a, roughness: 0.3 })
                    );
                    cube2.position.set(-0.6, 4.6, -1.0);
                    luxury.add(cube2);

                    // Panorama-Glasfronten
                    const glassFront = new THREE.Mesh(
                        new THREE.BoxGeometry(0.1, 4.8, 4.2),
                        new THREE.MeshStandardMaterial({ color: 0x38bdf8, metalness: 0.8, roughness: 0.1 })
                    );
                    glassFront.position.set(-4.3, 3.0, 1.5);
                    luxury.add(glassFront);

                    // SWIMMING-POOL im Garten
                    const poolBasin = new THREE.Mesh(
                        new THREE.BoxGeometry(4.6, 0.15, 3.4),
                        new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.2 })
                    );
                    poolBasin.position.set(-2.8, 0.15, -2.8);
                    group.add(poolBasin);

                    const poolWater = new THREE.Mesh(
                        new THREE.BoxGeometry(4.2, 0.2, 3.0),
                        new THREE.MeshBasicMaterial({ map: this.getOceanTexture() })
                    );
                    poolWater.position.set(-2.8, 0.2, -2.8);
                    group.add(poolWater);

                    // Palmen am Pool (sicher hinter dem Zaun im Garten, leicht nach innen geneigt)
                    const palmTrunk = new THREE.Mesh(
                        new THREE.CylinderGeometry(0.14, 0.2, 3.6, 8),
                        new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.8 })
                    );
                    palmTrunk.position.set(-2.8, 1.8, -4.8);
                    palmTrunk.rotation.z = 0.10;
                    group.add(palmTrunk);

                    const palmCrown = new THREE.Mesh(
                        new THREE.SphereGeometry(1.6, 8, 8),
                        new THREE.MeshStandardMaterial({ color: 0x16a34a, roughness: 0.7 })
                    );
                    palmCrown.position.set(-2.6, 3.6, -4.8);
                    palmCrown.scale.set(1.4, 0.5, 1.4);
                    group.add(palmCrown);

                    group.add(luxury);

                } else if (level === 5) {
                    // --- LEVEL 5: GRAND SCHLOSS / PALAIS MIT ECKTÜRMEN & SPRINGBRUNNEN ---
                    const castle = new THREE.Group();
                    castle.position.set(4.0, 0, 0);

                    // Zentraler Palasttrakt (Monumentaler Sandstein mit Textur)
                    const palace = new THREE.Mesh(
                        new THREE.BoxGeometry(9.6, 8.2, 8.5),
                        new THREE.MeshStandardMaterial({ map: this.getModernFacadeTexture('#fef08a'), roughness: 0.4 })
                    );
                    palace.position.set(0, 4.1, 0);
                    castle.add(palace);

                    // Mansard-Dach mit Ziegeltextur
                    const roof = new THREE.Mesh(
                        new THREE.BoxGeometry(10.2, 1.2, 9.2),
                        new THREE.MeshStandardMaterial({ map: this.getRoofTilesTexture(), roughness: 0.4 })
                    );
                    roof.position.set(0, 8.6, 0);
                    castle.add(roof);

                    // 2 Majestätische Ecktürme an den Fronten
                    [-4.5, 4.5].forEach(tz => {
                        const tower = new THREE.Mesh(
                            new THREE.CylinderGeometry(1.4, 1.5, 10.5, 16),
                            new THREE.MeshStandardMaterial({ color: 0xfef08a, roughness: 0.4 })
                        );
                        tower.position.set(-3.5, 5.25, tz);
                        castle.add(tower);

                        // Kegeldach in Spielerfarbe mit Goldspitze
                        const towerCone = new THREE.Mesh(
                            new THREE.ConeGeometry(1.8, 3.2, 16),
                            new THREE.MeshStandardMaterial({ color: ownerHex, roughness: 0.3, metalness: 0.4 })
                        );
                        towerCone.position.set(-3.5, 12.0, tz);
                        castle.add(towerCone);

                        const goldTip = new THREE.Mesh(
                            new THREE.SphereGeometry(0.3, 8, 8),
                            new THREE.MeshStandardMaterial({ color: 0xf59e0b, metalness: 0.9, roughness: 0.2 })
                        );
                        goldTip.position.set(-3.5, 13.8, tz);
                        castle.add(goldTip);
                    });

                    // Zierbalkon am Mitteltrakt
                    const grandBalcony = new THREE.Mesh(
                        new THREE.BoxGeometry(1.6, 0.4, 4.2),
                        new THREE.MeshStandardMaterial({ color: 0xfef08a })
                    );
                    grandBalcony.position.set(-5.2, 4.8, 0);
                    castle.add(grandBalcony);

                    // PRUNKVOLLER SPRINGBRUNNEN im Vorgarten
                    const fountainBasin = new THREE.Mesh(
                        new THREE.CylinderGeometry(1.9, 2.1, 0.4, 16),
                        new THREE.MeshStandardMaterial({ color: 0xe2e8f0, roughness: 0.5 })
                    );
                    fountainBasin.position.set(-3.6, 0.2, 0);
                    group.add(fountainBasin);

                    const fountainWater = new THREE.Mesh(
                        new THREE.CylinderGeometry(1.7, 1.7, 0.45, 16),
                        new THREE.MeshBasicMaterial({ color: 0x0284c7 })
                    );
                    fountainWater.position.set(-3.6, 0.22, 0);
                    group.add(fountainWater);

                    const fountainJet = new THREE.Mesh(
                        new THREE.CylinderGeometry(0.12, 0.35, 1.6, 8),
                        new THREE.MeshBasicMaterial({ color: 0xbae6fd, transparent: true, opacity: 0.85 })
                    );
                    fountainJet.position.set(-3.6, 1.0, 0);
                    group.add(fountainJet);

                    // Buchsbaum-Rondelle
                    [-2.2, 2.2].forEach(bz => {
                        const hedge = new THREE.Mesh(
                            new THREE.CylinderGeometry(0.7, 0.7, 0.6, 12),
                            new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.8 })
                        );
                        hedge.position.set(-3.6, 0.3, bz);
                        group.add(hedge);
                    });

                    group.add(castle);
                }
            }
        }

        // --- D. SPEZIELLE FELDER (1:1 UMSETZUNG DER REFERENZBILDER DES USERS!) ---
        renderSpecialPlot(group, tileType, tileName, tileIndex = 0) {
            // Grundstücksboden (für normale Felder; das Autokino hat sein eigenes sauberes Areal)
            if (tileType !== 'parking') {
                const plotMat = new THREE.MeshStandardMaterial({ color: 0x1e293b, roughness: 0.85 });
                const plot = new THREE.Mesh(new THREE.BoxGeometry(20, 0.35, 18), plotMat);
                plot.position.set(0, 0.18, 0);
                plot.receiveShadow = true;
                group.add(plot);
            }

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
                // 6. AUTOKINO & SUNSET POINT 🎬🌅 (EXAKT WIE IM REFERENZBILD DES USERS!)
                // =============================================================
                const cinemaGroup = new THREE.Group();
                cinemaGroup.position.set(3.5, 0, 0);

                // Schotter- & Gras-Areal des Autokinos (Einheitlicher, flackerfreier Grundstücksboden)
                const groundMat = new THREE.MeshStandardMaterial({ color: 0x65a30d, roughness: 0.95 });
                const cinemaGround = new THREE.Mesh(new THREE.BoxGeometry(22.0, 0.45, 19.0), groundMat);
                cinemaGround.position.set(0.5, 0.22, 0);
                cinemaGround.receiveShadow = true;
                cinemaGroup.add(cinemaGround);

                // Schotter-Zufahrtsstraße (leicht erhaben, kein Z-Fighting)
                const gravelMat = new THREE.MeshStandardMaterial({ color: 0xa8a29e, roughness: 0.95 });
                const driveWay = new THREE.Mesh(new THREE.BoxGeometry(7.0, 0.04, 18.0), gravelMat);
                driveWay.position.set(-5.0, 0.46, 0);
                driveWay.receiveShadow = true;
                cinemaGroup.add(driveWay);

                // =========================================================
                // 1. DAS MONUMENTALE EINGANGS-PORTAL (BILLBOARD AUF PFEILERN)
                // =========================================================
                const poleMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, metalness: 0.7, roughness: 0.25 });
                const pLeft = new THREE.Mesh(new THREE.CylinderGeometry(0.18, 0.18, 8.2, 16), poleMat);
                pLeft.position.set(-6.2, 4.1, -4.5);
                cinemaGroup.add(pLeft);

                const pRight = new THREE.Mesh(new THREE.CylinderGeometry(0.18, 0.18, 8.2, 16), poleMat);
                pRight.position.set(-6.2, 4.1, 4.5);
                cinemaGroup.add(pRight);

                // Großes zweigeteiltes Billboard: "AUTOKINO" + "& Sunset Point" (Canvas-Textur!)
                const signMat = new THREE.MeshStandardMaterial({ map: this.getAutokinoSignTexture(), roughness: 0.3 });
                const mainBillboard = new THREE.Mesh(new THREE.BoxGeometry(0.25, 4.5, 9.2), signMat);
                mainBillboard.position.set(-6.2, 5.6, 0);
                mainBillboard.castShadow = true;
                cinemaGroup.add(mainBillboard);

                // Bunte Wimpelketten / Fahnen an Seilen (Rot, Gelb, Cyan)
                const flagColors = [0xef4444, 0xfacc15, 0x06b6d4, 0x22c55e, 0xf97316];
                for (let f = 0; f < 8; f++) {
                    const flag = new THREE.Mesh(new THREE.ConeGeometry(0.2, 0.45, 3), new THREE.MeshBasicMaterial({ color: flagColors[f % flagColors.length] }));
                    flag.position.set(-6.15, 3.8 - (f % 3) * 0.15, -4.0 + f * 1.1);
                    flag.rotation.x = Math.PI;
                    cinemaGroup.add(flag);
                }

                // =========================================================
                // 2. KASSENHÄUSCHEN ("EINFAHRT / TICKETS") MIT SCHRANKE
                // =========================================================
                const shedMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.9 });
                const ticketShed = new THREE.Mesh(new THREE.BoxGeometry(2.4, 2.7, 2.6), shedMat);
                ticketShed.position.set(-1.8, 1.9, 4.8);
                ticketShed.castShadow = true;
                cinemaGroup.add(ticketShed);

                // Satteldach
                const roof = new THREE.Mesh(new THREE.ConeGeometry(2.2, 0.9, 4), new THREE.MeshStandardMaterial({ color: 0x451a03 }));
                roof.position.set(-1.8, 3.7, 4.8);
                roof.rotation.y = Math.PI / 4;
                cinemaGroup.add(roof);

                // Kassenfenster
                const shedWin = new THREE.Mesh(new THREE.BoxGeometry(0.1, 1.0, 1.2), new THREE.MeshBasicMaterial({ color: 0xfef08a }));
                shedWin.position.set(-3.05, 2.2, 4.8);
                cinemaGroup.add(shedWin);

                // Kassen-Schranke
                const barrier = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 4.2, 8), new THREE.MeshStandardMaterial({ color: 0xf8fafc }));
                barrier.position.set(-3.2, 1.1, 2.2);
                barrier.rotation.x = Math.PI / 2;
                cinemaGroup.add(barrier);

                // Wegweiser-Schild "Sunset Point ➔"
                const arrowPole = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 1.8, 8), shedMat);
                arrowPole.position.set(-4.5, 1.2, -4.8);
                cinemaGroup.add(arrowPole);
                const arrowSign = new THREE.Mesh(new THREE.BoxGeometry(0.08, 0.45, 1.4), new THREE.MeshStandardMaterial({ color: 0x451a03 }));
                arrowSign.position.set(-4.5, 1.8, -4.8);
                cinemaGroup.add(arrowSign);

                // =========================================================
                // 3. RIESIGE MONUMENTALE PROJEKTIONS-LEINWAND IM HINTERGRUND
                // =========================================================
                const screenMat = new THREE.MeshBasicMaterial({ map: this.getAutokinoScreenTexture() });
                const bigScreen = new THREE.Mesh(new THREE.BoxGeometry(0.3, 7.2, 13.5), screenMat);
                bigScreen.position.set(9.5, 4.8, 0);
                bigScreen.castShadow = true;
                cinemaGroup.add(bigScreen);

                // Stahl-Trägergerüst hinter der Leinwand
                const scaffoldMat = new THREE.MeshStandardMaterial({ color: 0x334155, metalness: 0.7 });
                [-5.5, 0, 5.5].forEach(sz => {
                    const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.12, 0.12, 7.5, 8), scaffoldMat);
                    leg.position.set(10.2, 4.2, sz);
                    leg.rotation.z = 0.2;
                    cinemaGroup.add(leg);
                });

                // =========================================================
                // 4. AUTOKINO-PARKREIHEN MIT LAUTSPRECHER-SÄULEN & AUTOS
                // =========================================================
                // Lautsprechersäulen zwischen den Parkreihen
                [-2.5, 2.5].forEach(pz => {
                    [1.5, 5.0].forEach(px => {
                        const speakerPost = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 1.6, 8), scaffoldMat);
                        speakerPost.position.set(px, 1.35, pz);
                        cinemaGroup.add(speakerPost);
                        const speakerBox = new THREE.Mesh(new THREE.BoxGeometry(0.25, 0.25, 0.35), new THREE.MeshStandardMaterial({ color: 0x18181b }));
                        speakerBox.position.set(px, 2.1, pz);
                        cinemaGroup.add(speakerBox);
                    });
                });

                // 2 geparkte Autos vor der Leinwand
                [
                    { x: 3.5, z: -2.2, col: 0x0284c7 },
                    { x: 4.2, z: 2.2, col: 0xf97316 },
                ].forEach(car => {
                    const cBody = new THREE.Mesh(new THREE.BoxGeometry(3.6, 0.7, 1.8), new THREE.MeshStandardMaterial({ color: car.col, roughness: 0.4 }));
                    cBody.position.set(car.x, 0.95, car.z);
                    cinemaGroup.add(cBody);
                    const cTop = new THREE.Mesh(new THREE.BoxGeometry(1.8, 0.55, 1.5), new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.3 }));
                    cTop.position.set(car.x - 0.3, 1.5, car.z);
                    cinemaGroup.add(cTop);
                });

                // =========================================================
                // 5. SNACK-BAR / FOOD-TRUCK & SUNSET OVERLOOK
                // =========================================================
                const foodTruck = new THREE.Mesh(new THREE.BoxGeometry(2.4, 2.2, 4.2), new THREE.MeshStandardMaterial({ color: 0x94a3b8, metalness: 0.6 }));
                foodTruck.position.set(1.5, 1.65, -6.2);
                cinemaGroup.add(foodTruck);

                // Sonnenschirm & Picknicktisch
                const umbrella = new THREE.Mesh(new THREE.ConeGeometry(1.8, 0.6, 12), new THREE.MeshStandardMaterial({ color: 0xef4444 }));
                umbrella.position.set(4.5, 2.6, -6.2);
                cinemaGroup.add(umbrella);
                const uPole = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.05, 2.0, 8), poleMat);
                uPole.position.set(4.5, 1.5, -6.2);
                cinemaGroup.add(uPole);

                const table = new THREE.Mesh(new THREE.BoxGeometry(1.4, 0.75, 2.2), shedMat);
                table.position.set(4.5, 0.95, -6.2);
                cinemaGroup.add(table);

                // Aussichts-Geländer zum Meer ("Sunset Point")
                const sunsetRail = new THREE.Mesh(new THREE.BoxGeometry(0.1, 0.9, 14.0), new THREE.MeshStandardMaterial({ color: 0x451a03 }));
                sunsetRail.position.set(10.8, 1.0, 0);
                cinemaGroup.add(sunsetRail);

                // Schattenspendende Kiefern im Hintergrund
                [ { x: 7.5, z: 6.8 }, { x: 8.5, z: -6.5 } ].forEach(tree => {
                    const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.2, 0.3, 3.5, 8), shedMat);
                    trunk.position.set(tree.x, 2.2, tree.z);
                    cinemaGroup.add(trunk);
                    const foliage = new THREE.Mesh(new THREE.SphereGeometry(2.4, 8, 8), new THREE.MeshStandardMaterial({ color: 0x15803d, roughness: 0.85 }));
                    foliage.position.set(tree.x, 4.8, tree.z);
                    foliage.scale.set(1.2, 0.8, 1.2);
                    cinemaGroup.add(foliage);
                });

                group.add(cinemaGroup);

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
            const from = Number.isFinite(fromIndex) ? Math.floor(fromIndex) : (parseInt(fromIndex, 10) || 0);
            const to = Number.isFinite(toIndex) ? Math.floor(toIndex) : (parseInt(toIndex, 10) || 0);

            const steps = (to >= from) ? (to - from) : (40 - from + to);
            if (steps <= 0 || steps >= 40 || !this.trackCurve) {
                this.isDriving = false;
                const tp = this.trackPoints ? this.trackPoints[to % 40] : null;
                if (tp && this.cockpitRig) {
                    const carPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(3.2));
                    this.cockpitRig.position.copy(carPos);
                    this.cockpitRig.rotation.y = tp.angle;
                }
                if (onComplete) onComplete();
                return;
            }

            this.isDriving = true;
            this.audio.playEngine();

            // Während der Fahrt: Blick exakt geradeaus auf die Straße!
            this.targetYaw = 0.0;
            this.targetPitch = 0.0;

            const totalDuration = Math.max(1400, steps * 280);
            const startTime = performance.now();

            const stepLoop = (now) => {
                if (!this.trackCurve) {
                    this.isDriving = false;
                    if (onComplete) onComplete();
                    return;
                }

                const elapsed = now - startTime;
                const progress = Math.min(Math.max(elapsed / totalDuration, 0), 1);

                // Sanfte Ease-In-Out Beschleunigung
                const eased = progress < 0.5
                    ? 4 * progress * progress * progress
                    : 1 - Math.pow(-2 * progress + 2, 3) / 2;

                let currentU = (from / 40.0 + (steps / 40.0) * eased) % 1.0;
                if (!Number.isFinite(currentU) || isNaN(currentU)) currentU = 0;
                if (currentU < 0) currentU += 1.0;
                currentU = Math.min(Math.max(currentU, 0), 0.9999);

                try {
                    const pos = this.trackCurve.getPointAt(currentU);
                    const tangent = this.trackCurve.getTangentAt(currentU).normalize();
                    const normal = new THREE.Vector3(-tangent.z, 0, tangent.x).normalize();

                    // Auto fährt flüssig auf der rechten Fahrspur (x = +3.2m)
                    const carPos = pos.clone().add(normal.clone().multiplyScalar(3.2));
                    this.cockpitRig.position.copy(carPos);
                    this.cockpitRig.rotation.y = Math.atan2(-tangent.x, -tangent.z);
                } catch (err) {
                    console.warn('Track curve point calculation error:', err);
                }

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
            if (this.ui.sheetBtnSellBuilding) {
                this.ui.sheetBtnSellBuilding.addEventListener('click', () => this.actionSellBuilding());
            }
            if (this.ui.sheetBtnSellProperty) {
                this.ui.sheetBtnSellProperty.addEventListener('click', () => this.actionSellProperty());
            }

            if (this.ui.btnPortfolio) {
                this.ui.btnPortfolio.addEventListener('click', () => this.openPortfolioModal());
            }
            if (this.ui.portfolioClose) {
                this.ui.portfolioClose.addEventListener('click', () => this.closePortfolioModal());
            }
            if (this.ui.naviHeaderClickable) {
                this.ui.naviHeaderClickable.addEventListener('click', () => this.openPortfolioModal());
            }

            if (this.ui.btnTrade) {
                this.ui.btnTrade.addEventListener('click', () => this.openTradeModal());
            }
            if (this.ui.tradeClose) {
                this.ui.tradeClose.addEventListener('click', () => this.closeTradeModal());
            }
            if (this.ui.btnSubmitTrade) {
                this.ui.btnSubmitTrade.addEventListener('click', () => this.submitTrade());
            }
            if (this.ui.btnAcceptTrade) {
                this.ui.btnAcceptTrade.addEventListener('click', () => this.respondTrade(true));
            }
            if (this.ui.btnRejectTrade) {
                this.ui.btnRejectTrade.addEventListener('click', () => this.respondTrade(false));
            }
            if (this.ui.btnCloseJailAlert) {
                this.ui.btnCloseJailAlert.addEventListener('click', () => {
                    if (this.ui.modalJailAlert) this.ui.modalJailAlert.classList.remove('open');
                });
            }

            if (this.ui.btnCloseCardAlert) {
                this.ui.btnCloseCardAlert.addEventListener('click', () => {
                    if (this.ui.modalCardAlert) this.ui.modalCardAlert.classList.remove('open');
                });
            }

            if (this.ui.btnUseJailCard) {
                this.ui.btnUseJailCard.addEventListener('click', () => this.actionUseJailCard());
            }

            document.getElementById('btn-audio-toggle').addEventListener('click', (e) => {
                this.audio.enabled = !this.audio.enabled;
                e.currentTarget.textContent = this.audio.enabled ? '🔊' : '🔇';
            });

            const btnLeave = document.getElementById('btn-leave');
            if (btnLeave) {
                btnLeave.addEventListener('click', () => {
                    if (this.ui.modalLeaveConfirm) {
                        this.ui.modalLeaveConfirm.classList.add('open');
                    } else {
                        window.location.href = '../index.php';
                    }
                });
            }
            if (this.ui.btnCancelLeave) {
                this.ui.btnCancelLeave.addEventListener('click', () => {
                    if (this.ui.modalLeaveConfirm) this.ui.modalLeaveConfirm.classList.remove('open');
                });
            }
            if (this.ui.btnConfirmLeave) {
                this.ui.btnConfirmLeave.addEventListener('click', () => {
                    if (this.roomCode) {
                        localStorage.removeItem('quarxl_hood_token_' + this.roomCode);
                    }
                    window.location.href = '../index.php';
                });
            }
            const btnLeaveToLobby = document.getElementById('btn-leave-to-lobby');
            if (btnLeaveToLobby) {
                btnLeaveToLobby.addEventListener('click', () => {
                    if (this.roomCode) {
                        localStorage.removeItem('quarxl_hood_token_' + this.roomCode);
                    }
                    window.location.href = './index.php';
                });
            }

            const btnRestart = document.getElementById('btn-restart-game');
            if (btnRestart) {
                btnRestart.addEventListener('click', () => {
                    if (this.roomCode) {
                        localStorage.removeItem('quarxl_hood_token_' + this.roomCode);
                    }
                    window.location.href = './index.php';
                });
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
                    if (data.me) {
                        this.applyState(data.state, data.me);
                    } else {
                        this.showJoinModal(data.state);
                    }
                } else if (res.status === 404) {
                    await this.showAlert('Dieser Spielraum existiert nicht oder wurde beendet.', 'RAUM BEENDET', '🏁');
                    window.location.href = './index.php';
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

        // Liefert die ID des aktuell handelnden / aktiven Spielers (im Hot-Seat der aktive Fahrer, sonst me)
        getActivePlayerId() {
            if (!this.state || !this.state.players) return this.myPlayerId;
            if (this.state.is_hotseat) {
                const active = this.state.players[this.state.current_player_index];
                if (active) return active.id;
            }
            return this.myPlayerId;
        }

        getActivePlayer() {
            if (!this.state || !this.state.players) return null;
            if (this.state.is_hotseat) {
                return this.state.players[this.state.current_player_index] || null;
            }
            return this.state.players.find(p => p.id === this.myPlayerId) || null;
        }

        escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        /**
         * Zeigt eine stylische In-Game Toast-Benachrichtigung anstelle eines nativen Browser-Alerts
         * @param {string} message
         * @param {'info'|'warning'|'error'|'success'} type
         * @param {number} duration In Millisekunden
         */
        showToast(message, type = 'info', duration = 3200) {
            const container = document.getElementById('hood-toast-container') || document.body;
            const toast = document.createElement('div');
            toast.className = `hood-toast toast-${type}`;

            const icons = {
                info: '🏎️',
                warning: '⚠️',
                error: '🛑',
                success: '✅'
            };
            const icon = icons[type] || '🏎️';

            toast.innerHTML = `
                <span class="hood-toast-icon">${icon}</span>
                <span class="hood-toast-msg">${this.escapeHtml(message)}</span>
            `;

            toast.addEventListener('click', () => {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 250);
            });

            container.appendChild(toast);

            setTimeout(() => {
                if (toast.parentNode) {
                    toast.classList.add('fade-out');
                    setTimeout(() => toast.remove(), 250);
                }
            }, duration);
        }

        /**
         * Öffnet ein stilvolles modales Bestätigungsfenster (Ersatz für window.confirm)
         * @param {string} message
         * @param {string} title
         * @param {string} icon
         * @returns {Promise<boolean>}
         */
        showConfirm(message, title = 'BESTÄTIGUNG', icon = '⚠️') {
            return new Promise((resolve) => {
                const modal = document.getElementById('modal-custom-confirm');
                const titleEl = document.getElementById('custom-confirm-title');
                const textEl = document.getElementById('custom-confirm-text');
                const iconEl = document.getElementById('custom-confirm-icon');
                const btnOk = document.getElementById('btn-custom-confirm-ok');
                const btnCancel = document.getElementById('btn-custom-confirm-cancel');

                if (!modal || !btnOk || !btnCancel) {
                    resolve(window.confirm(message));
                    return;
                }

                if (titleEl) titleEl.textContent = title;
                if (textEl) textEl.textContent = message;
                if (iconEl) iconEl.textContent = icon;

                const cleanup = (val) => {
                    modal.classList.remove('open');
                    btnOk.removeEventListener('click', onOk);
                    btnCancel.removeEventListener('click', onCancel);
                    resolve(val);
                };

                const onOk = () => cleanup(true);
                const onCancel = () => cleanup(false);

                btnOk.addEventListener('click', onOk);
                btnCancel.addEventListener('click', onCancel);

                modal.classList.add('open');
            });
        }

        /**
         * Öffnet ein modales Hinweisfenster (Ersatz für blockierende alerts)
         * @param {string} message
         * @param {string} title
         * @param {string} icon
         * @returns {Promise<void>}
         */
        showAlert(message, title = 'HINWEIS', icon = '🏁') {
            return new Promise((resolve) => {
                const modal = document.getElementById('modal-custom-alert');
                const titleEl = document.getElementById('custom-alert-title');
                const textEl = document.getElementById('custom-alert-text');
                const iconEl = document.getElementById('custom-alert-icon');
                const btnOk = document.getElementById('btn-custom-alert-ok');

                if (!modal || !btnOk) {
                    window.alert(message);
                    resolve();
                    return;
                }

                if (titleEl) titleEl.textContent = title;
                if (textEl) textEl.textContent = message;
                if (iconEl) iconEl.textContent = icon;

                const onOk = () => {
                    modal.classList.remove('open');
                    btnOk.removeEventListener('click', onOk);
                    resolve();
                };

                btnOk.addEventListener('click', onOk);
                modal.classList.add('open');
            });
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

            if (this.ui.potBadgeAmount) {
                this.ui.potBadgeAmount.textContent = `${newState.parking_pot ?? 0} $`;
            }

            this.ui.roomBadge.textContent = this.roomCode;

            if (newState.log && newState.log.length > 0) {
                this.ui.tickerText.textContent = newState.log[newState.log.length - 1].text;
            }

            // Grundstücke 3D aktualisieren
            if (newState.tiles) {
                newState.tiles.forEach(t => {
                    const owner = newState.players.find(p => p.id === t.owner);
                    this.renderPlotState(t, owner ? owner.color : null, t.mortgaged, owner ? owner.name : null);
                });
            }

            // Fahrt animieren
            const activePlayer = (newState.players && newState.current_player_index !== undefined)
                ? newState.players[newState.current_player_index]
                : null;
            if (activePlayer) {
                const newPos = Number.isFinite(activePlayer.position) ? Math.floor(activePlayer.position) : 0;
                if (oldState && !this.isDriving) {
                    const oldActive = oldState.players ? oldState.players.find(p => p.id === activePlayer.id) : null;
                    const oldPos = (oldActive && Number.isFinite(oldActive.position)) ? Math.floor(oldActive.position) : null;
                    if (oldPos !== null && oldPos !== newPos) {
                        this.driveToTile(oldPos, newPos, () => {
                            this.updateNaviScreen(newState, activePlayer);
                        });
                    } else {
                        const tp = this.trackPoints ? this.trackPoints[newPos % 40] : null;
                        if (tp && this.cockpitRig) {
                            const carPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(3.2));
                            this.cockpitRig.position.copy(carPos);
                            this.cockpitRig.rotation.y = tp.angle;
                        }
                        this.updateNaviScreen(newState, activePlayer);
                    }
                } else if (!this.isDriving) {
                    const tp = this.trackPoints ? this.trackPoints[newPos % 40] : null;
                    if (tp && this.cockpitRig) {
                        const carPos = tp.pos.clone().add(tp.normal.clone().multiplyScalar(3.2));
                        this.cockpitRig.position.copy(carPos);
                        this.cockpitRig.rotation.y = tp.angle;
                    }
                    this.updateNaviScreen(newState, activePlayer);
                }
            }

            // Würfelanzeige aktualisieren (ohne blockierende Animation bei Fremd-/Bot-Zügen!)
            if (newState.last_dice && Array.isArray(newState.last_dice)) {
                this.lastRenderedDice = [...newState.last_dice];
                const d1 = document.getElementById('die-1');
                const d2 = document.getElementById('die-2');
                if (d1 && !this.isRollingAnimation) d1.textContent = newState.last_dice[0];
                if (d2 && !this.isRollingAnimation) d2.textContent = newState.last_dice[1];
            }

            // Prüfen ob Spiel beendet ist -> Nur Sieger-Modal anzeigen, keine Werkstatt-Alerts
            if (newState.status === 'finished' && newState.winner_id) {
                if (this.ui.modalJailAlert) this.ui.modalJailAlert.classList.remove('open');
                if (this.ui.modalCardAlert) this.ui.modalCardAlert.classList.remove('open');
                const winner = newState.players.find(p => p.id === newState.winner_id);
                if (winner) {
                    this.ui.winnerText.textContent = `👑 ${winner.name} gewinnt die Hood!`;
                    this.ui.winnerModal.classList.add('open');
                }
                return;
            }

            // Werkstatt / Gefängnis Alert prüfen
            if (newState.jail_alert) {
                const isMe = (activePlayer && activePlayer.id === this.myPlayerId) || !!newState.is_hotseat;
                if (isMe && this.ui.modalJailAlert && this.lastSeenJailAlert !== newState.jail_alert.reason) {
                    this.lastSeenJailAlert = newState.jail_alert.reason;
                    if (this.ui.jailAlertReason) {
                        this.ui.jailAlertReason.textContent = newState.jail_alert.reason;
                    }
                    this.ui.modalJailAlert.classList.add('open');
                }
            } else if (!newState.jail_alert && this.ui.modalJailAlert) {
                this.ui.modalJailAlert.classList.remove('open');
            }

            // Prominentes Ereigniskarten-Modal prüfen (Radio Ticker & Handschuhfach)
            if (newState.last_card && newState.last_card.text) {
                const card = newState.last_card;
                const cardId = card.draw_id || card.text;
                const isMe = (activePlayer && activePlayer.id === this.myPlayerId) || !!newState.is_hotseat;
                if (isMe && this.lastSeenCardId !== cardId && this.ui.modalCardAlert) {
                    this.lastSeenCardId = cardId;
                    const isRadio = (card.deck === 'radio');
                    if (this.ui.cardAlertIcon) this.ui.cardAlertIcon.textContent = isRadio ? '📻' : '🗃️';
                    if (this.ui.cardAlertBadge) this.ui.cardAlertBadge.textContent = isRadio ? 'RADIO TICKER' : 'HANDSCHUHFACH';
                    if (this.ui.cardAlertTitle) this.ui.cardAlertTitle.textContent = isRadio ? 'NEUE MELDUNG AUS DER HOOD' : 'AUS DEM FACH GEZOGEN';
                    if (this.ui.cardAlertText) this.ui.cardAlertText.textContent = card.text;

                    if (this.ui.cardAlertSub) {
                        if (card.action === 'get_out_of_jail_free') {
                            this.ui.cardAlertSub.innerHTML = '<span style="color: #38bdf8;">🔑 Notfall-Schlüsselkarte in dein Inventar gelegt!</span>';
                        } else if (card.amount > 0) {
                            this.ui.cardAlertSub.innerHTML = `<span style="color: #10b981;">💰 +${card.amount} $ gutgeschrieben!</span>`;
                        } else if (card.amount < 0) {
                            this.ui.cardAlertSub.innerHTML = `<span style="color: #ef4444;">💸 ${card.amount} $ an die Kasse gezahlt!</span>`;
                        } else {
                            this.ui.cardAlertSub.innerHTML = '';
                        }
                    }
                    this.ui.modalCardAlert.classList.add('open');
                }
            }

            // Eingehendes Handelsangebot prüfen
            if (newState.active_trade && this.ui.modalTradeIncoming) {
                const isTarget = (newState.active_trade.to_id === this.myPlayerId) ||
                    (!!newState.is_hotseat && this.isHost);
                if (isTarget && this.lastSeenTradeId !== newState.active_trade.id) {
                    this.lastSeenTradeId = newState.active_trade.id;
                    this.renderIncomingTradeModal(newState.active_trade);
                }
            } else if (!newState.active_trade && this.ui.modalTradeIncoming) {
                this.ui.modalTradeIncoming.classList.remove('open');
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

                    const curRent = this.getTileRent(tile);
                    if (tile.type === 'club') {
                        const ownerClubCount = owner ? state.tiles.filter(t => t.type === 'club' && t.owner === owner.id).length : 0;
                        this.ui.naviRent.textContent = (ownerClubCount >= 2) ? '10x Wurf' : '4x Wurf';
                    } else {
                        this.ui.naviRent.textContent = `${curRent} $`;
                    }

                    if (this.ui.naviLevel) {
                        if (tile.type === 'street') {
                            const curLvl = tile.level || 0;
                            const levelNames = ['Unbebaut', 'Holzhütte', 'Einfamilienhaus', 'Stadtvilla', 'Residenz', 'Schloss'];
                            const levelIcons = ['0/5', '🏕️ 1/5', '🏡 2/5', '🏛️ 3/5', '🏰 4/5', '👑 5/5'];
                            this.ui.naviLevel.textContent = tile.mortgaged ? 'Hypothek' : (levelIcons[curLvl] || `${curLvl}/5`);
                            this.ui.naviLevel.style.color = tile.mortgaged ? '#ef4444' : (curLvl === 5 ? '#f59e0b' : '#38bdf8');
                            this.ui.naviLevel.title = `Stufe ${curLvl}/5: ${levelNames[curLvl] || ''}`;
                        } else if (tile.type === 'beach') {
                            const ownerBeachCount = owner ? state.tiles.filter(t => t.type === 'beach' && t.owner === owner.id).length : 0;
                            this.ui.naviLevel.textContent = `${ownerBeachCount}/4`;
                            this.ui.naviLevel.style.color = '#38bdf8';
                            this.ui.naviLevel.title = `${ownerBeachCount} von 4 Stränden im Besitz`;
                        } else if (tile.type === 'club') {
                            const ownerClubCount = owner ? state.tiles.filter(t => t.type === 'club' && t.owner === owner.id).length : 0;
                            this.ui.naviLevel.textContent = `${ownerClubCount}/2`;
                            this.ui.naviLevel.style.color = '#c084fc';
                            this.ui.naviLevel.title = `${ownerClubCount} von 2 Clubs im Besitz`;
                        } else {
                            this.ui.naviLevel.textContent = '-';
                            this.ui.naviLevel.style.color = 'var(--hood-muted)';
                        }
                    }
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

            const groupData = this.getGroupData(tile.group);
            this.ui.naviZoneTag.textContent = tile.group ? groupData.name : tile.type.toUpperCase();
            if (tile.group && groupData.color) {
                this.ui.naviZoneTag.style.background = groupData.color;
            }

            // Status des Viertels & Monopols in der Cockpit-Navi anzeigen
            if (this.ui.naviMonopolyStatus) {
                if (tile.group) {
                    const groupInfo = this.checkGroupOwnership(tile.group);
                    this.ui.naviMonopolyStatus.style.display = 'block';

                    if (groupInfo && groupInfo.isFullMonopoly) {
                        const monoOwner = state.players.find(p => p.id === groupInfo.monopolyOwnerId);
                        const isMine = (groupInfo.monopolyOwnerId === this.getActivePlayerId());
                        this.ui.naviMonopolyStatus.style.color = '#f59e0b';
                        this.ui.naviMonopolyStatus.innerHTML = isMine
                            ? `👑 <strong>Monopol komplett!</strong> Ausbau bereit (Miete verdoppelt)`
                            : `👑 <strong>Monopol von ${monoOwner ? monoOwner.name : 'Gegner'}!</strong> (Doppelte Miete)`;
                    } else if (groupInfo) {
                        const myCount = this.getActivePlayerId() ? (groupInfo.ownerCounts[this.getActivePlayerId()] || 0) : 0;
                        const ownedTotal = Object.values(groupInfo.ownerCounts).reduce((a, b) => a + b, 0);
                        this.ui.naviMonopolyStatus.style.color = 'var(--hood-muted)';
                        if (myCount > 0) {
                            this.ui.naviMonopolyStatus.innerHTML = `📍 Viertel: <strong>${myCount}/${groupInfo.total}</strong> in deinem Besitz (${groupData.name})`;
                        } else {
                            this.ui.naviMonopolyStatus.innerHTML = `📍 Viertel: ${ownedTotal}/${groupInfo.total} vergeben (${groupData.name})`;
                        }
                    }
                } else if (tile.type === 'beach') {
                    this.ui.naviMonopolyStatus.style.display = 'block';
                    this.ui.naviMonopolyStatus.style.color = 'var(--hood-muted)';
                    const beaches = state.tiles.filter(t => t.type === 'beach');
                    const myBeaches = this.getActivePlayerId() ? beaches.filter(b => b.owner === this.getActivePlayerId()).length : 0;
                    this.ui.naviMonopolyStatus.innerHTML = `🏖️ Küstenstrände: <strong>${myBeaches}/4</strong> in deinem Besitz`;
                } else {
                    this.ui.naviMonopolyStatus.style.display = 'none';
                }
            }

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
            if (this.ui.btnGroupJail) this.ui.btnGroupJail.style.display = 'none';
            if (this.ui.btnBail) this.ui.btnBail.style.display = 'none';
            if (this.ui.btnUseJailCard) this.ui.btnUseJailCard.style.display = 'none';

            if (!isMyTurn) return;

            if (state.phase === 'ROLL') {
                this.ui.btnRoll.style.display = 'flex';
                if (activePlayer.in_jail) {
                    if (this.ui.btnGroupJail) this.ui.btnGroupJail.style.display = 'flex';
                    if ((activePlayer.jail_free_cards || 0) > 0 && this.ui.btnUseJailCard) {
                        this.ui.btnUseJailCard.style.display = 'flex';
                    }
                    if (activePlayer.cash >= 50 && this.ui.btnBail) {
                        this.ui.btnBail.style.display = 'flex';
                    }
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
        playDiceRollAnimation(targetDice, onComplete) {
            const cluster = document.getElementById('dice-display');
            const d1 = document.getElementById('die-1');
            const d2 = document.getElementById('die-2');
            if (!cluster || !d1 || !d2) {
                if (onComplete) onComplete();
                return;
            }

            this.isRollingAnimation = true;
            cluster.classList.add('is-rolling');
            d1.classList.remove('landing');
            d2.classList.remove('landing');

            if ('vibrate' in navigator) {
                try { navigator.vibrate([25, 35, 25]); } catch (_) {}
            }

            this.audio.playRoll();

            let ticks = 0;
            const maxTicks = 8;
            const timer = setInterval(() => {
                ticks++;
                d1.textContent = Math.floor(Math.random() * 6) + 1;
                d2.textContent = Math.floor(Math.random() * 6) + 1;

                if (ticks >= maxTicks) {
                    clearInterval(timer);
                    cluster.classList.remove('is-rolling');

                    if (targetDice && targetDice.length >= 2) {
                        d1.textContent = targetDice[0];
                        d2.textContent = targetDice[1];
                    }

                    d1.classList.add('landing');
                    d2.classList.add('landing');

                    if ('vibrate' in navigator) {
                        try { navigator.vibrate([40]); } catch (_) {}
                    }

                    setTimeout(() => {
                        d1.classList.remove('landing');
                        d2.classList.remove('landing');
                        this.isRollingAnimation = false;
                        if (onComplete) onComplete();
                    }, 350);
                }
            }, 65);
        }

        async actionRoll() {
            if (this.isRollingAnimation) return;
            this.isRollingAnimation = true;
            this.ui.btnRoll.disabled = true;

            const res = await this.apiPost('roll');
            if (res.success && res.state) {
                if (res.state.last_dice) {
                    this.lastRenderedDice = [...res.state.last_dice];
                    this.playDiceRollAnimation(res.state.last_dice, () => {
                        this.ui.btnRoll.disabled = false;
                        this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                    });
                } else {
                    this.isRollingAnimation = false;
                    this.ui.btnRoll.disabled = false;
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                }
            } else {
                this.isRollingAnimation = false;
                this.ui.btnRoll.disabled = false;
                this.showToast(res.message || 'Würfeln fehlgeschlagen', 'error');
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
            } else {
                this.showToast(res.message || 'Kaution konnte nicht gezahlt werden', 'error');
            }
        }

        async actionUseJailCard() {
            this.audio.playHorn();
            const res = await this.apiPost('use_jail_free_card');
            if (res.success) {
                this.showToast('Notfall-Schlüsselkarte eingesetzt! Hebebühne kostenlos verlassen.', 'success');
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            } else {
                this.showToast(res.message || 'Konnte Schlüsselkarte nicht einsetzen', 'error');
            }
        }

        getGroupData(groupKey) {
            const groups = {
                docks: { name: 'Docks & Industrie', color: '#8B5A2B', badge: '🏭' },
                suburb: { name: 'Vorstadt & Diners', color: '#38BDF8', badge: '☕' },
                arts: { name: 'Künstlerviertel', color: '#EC4899', badge: '🎨' },
                promenade: { name: 'Flaniermeile', color: '#F97316', badge: '🌴' },
                downtown: { name: 'Downtown & Skyline', color: '#EF4444', badge: '🏙️' },
                marina: { name: 'Yachthafen & Marina', color: '#EAB308', badge: '⛵' },
                heights: { name: 'Panorama-Höhen', color: '#22C55E', badge: '⛰️' },
                millionaires: { name: 'Millionaires’ Hill', color: '#3B82F6', badge: '💎' },
            };
            return groups[groupKey] || { name: groupKey || 'Sondergebiet', color: '#64748b', badge: '📍' };
        }

        checkGroupOwnership(groupKey) {
            if (!this.state || !this.state.tiles || !groupKey) return null;
            const groupTiles = this.state.tiles.filter(t => t.group === groupKey);
            if (groupTiles.length === 0) return null;

            const firstOwner = groupTiles[0].owner;
            const isFullMonopoly = (firstOwner !== null && firstOwner !== undefined) &&
                groupTiles.every(t => t.owner === firstOwner);

            const ownerCounts = {};
            groupTiles.forEach(t => {
                if (t.owner !== null && t.owner !== undefined) {
                    ownerCounts[t.owner] = (ownerCounts[t.owner] || 0) + 1;
                }
            });

            return {
                tiles: groupTiles,
                total: groupTiles.length,
                isFullMonopoly,
                monopolyOwnerId: isFullMonopoly ? firstOwner : null,
                ownerCounts
            };
        }

        getTileRent(tile, diceSum = 7) {
            if (!tile) return 0;
            if (tile.owner === null || tile.owner === undefined || tile.mortgaged) return 0;

            const lvl = tile.level || 0;

            if (tile.type === 'street') {
                const rentTables = {
                    1: [2, 10, 30, 90, 160, 250],
                    3: [4, 20, 60, 180, 320, 450],
                    6: [6, 30, 90, 270, 400, 550],
                    8: [6, 30, 90, 270, 400, 550],
                    9: [8, 40, 100, 300, 450, 600],
                    11: [10, 50, 150, 450, 625, 750],
                    13: [10, 50, 150, 450, 625, 750],
                    14: [12, 60, 180, 500, 700, 900],
                    16: [14, 70, 200, 550, 750, 950],
                    18: [14, 70, 200, 550, 750, 950],
                    19: [16, 80, 220, 600, 800, 1000],
                    21: [18, 90, 250, 700, 875, 1050],
                    23: [18, 90, 250, 700, 875, 1050],
                    24: [20, 100, 300, 750, 925, 1100],
                    26: [22, 110, 330, 800, 975, 1150],
                    27: [22, 110, 330, 800, 975, 1150],
                    29: [24, 120, 360, 850, 1025, 1200],
                    31: [26, 130, 390, 900, 1100, 1275],
                    32: [26, 130, 390, 900, 1100, 1275],
                    34: [28, 150, 450, 1000, 1200, 1500],
                    37: [35, 175, 500, 1100, 1300, 1500],
                    39: [50, 200, 600, 1400, 1700, 2000],
                };
                const table = tile.rent || rentTables[tile.index] || [10, 50, 150, 450, 625, 750];
                const gInfo = this.checkGroupOwnership(tile.group);

                if (lvl <= 1) {
                    const base = table[0];
                    return (gInfo && gInfo.isFullMonopoly) ? (base * 2) : base;
                }
                const idx = Math.min(lvl - 1, table.length - 1);
                return table[idx];
            }

            if (tile.type === 'beach') {
                const rentScale = [25, 50, 100, 200];
                const beaches = [5, 15, 25, 35].map(i => this.state.tiles[i]).filter(Boolean);
                const count = beaches.filter(b => b.owner === tile.owner && !b.mortgaged).length;
                return rentScale[Math.max(0, Math.min(count - 1, 3))];
            }

            if (tile.type === 'club') {
                const clubs = [12, 28].map(i => this.state.tiles[i]).filter(Boolean);
                const count = clubs.filter(c => c.owner === tile.owner && !c.mortgaged).length;
                return (count >= 2) ? (10 * diceSum) : (4 * diceSum);
            }

            return 0;
        }

        async actionBuild() {
            if (this.selectedTileIndex === null) return;
            this.audio.playBuild();
            const res = await this.apiPost('build', { tile_index: this.selectedTileIndex });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                this.renderPortfolioModal();
            } else {
                this.showToast(res.message || 'Ausbau nicht möglich', 'error');
            }
        }

        async actionSellBuilding() {
            if (this.selectedTileIndex === null) return;
            this.audio.playCash();
            const res = await this.apiPost('sell_building', { tile_index: this.selectedTileIndex });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                this.renderPortfolioModal();
            } else {
                this.showToast(res.message || 'Verkauf nicht möglich', 'error');
            }
        }

        async actionSellProperty() {
            if (this.selectedTileIndex === null) return;
            const ok = await this.showConfirm('Möchtest du dieses Grundstück wirklich an die Bank verkaufen? Es ist danach wieder für alle frei.', 'VERKAUF AN DIE BANK', '💰');
            if (!ok) {
                return;
            }
            this.audio.playCash();
            const res = await this.apiPost('sell_property', { tile_index: this.selectedTileIndex });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                this.renderPortfolioModal();
            } else {
                this.showToast(res.message || 'Verkauf nicht möglich', 'error');
            }
        }

        async actionMortgage(tileIndex = null) {
            const idx = (tileIndex !== null) ? tileIndex : this.selectedTileIndex;
            if (idx === null || idx === undefined) return;
            this.audio.playCash();
            const res = await this.apiPost('mortgage', { tile_index: idx });
            if (res.success) {
                this.closePropertySheet();
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                this.renderPortfolioModal();
            } else {
                this.showToast(res.message || 'Aktion nicht möglich', 'error');
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

            const isMine = (tile.owner === this.getActivePlayerId());
            const groupData = this.getGroupData(tile.group);
            if (this.ui.sheetGroupBadge) {
                this.ui.sheetGroupBadge.textContent = `${groupData.badge} ${groupData.name}`;
                this.ui.sheetGroupBadge.style.color = groupData.color;
            }

            const groupInfo = this.checkGroupOwnership(tile.group);

            if (this.ui.sheetMonopolyInfo) {
                if (tile.type === 'street') {
                    const houseCost = tile.house_cost || 100;
                    const levelNames = [
                        'Unbebaut (Wildnis)',
                        'Garten & Holzhütte 🏕️',
                        'Einfamilienhaus 🏡',
                        'Gehobene Stadtvilla 🏛️',
                        'Luxus-Designer-Residenz 🏰',
                        'Grand Schloss & Palais 👑'
                    ];
                    const nextUpgrades = [
                        'Erschließen & Holzhütte 🏕️',
                        'Einfamilienhaus 🏡',
                        'Stadtvilla 🏛️',
                        'Luxus-Residenz 🏰',
                        'Grand Schloss 👑'
                    ];
                    const curLevelName = levelNames[Math.min(tile.level || 0, 5)];
                    const nextUpgradeName = nextUpgrades[Math.min(tile.level || 0, 4)];

                    if (isMine) {
                        if (groupInfo && groupInfo.isFullMonopoly) {
                            if ((tile.level || 0) < 5) {
                                this.ui.sheetMonopolyInfo.innerHTML = `👑 <strong style="color: #22c55e;">Vollständiges Viertel!</strong> Miete verdoppelt.<br>Aktuell: <em>${curLevelName}</em><br>Nächste Stufe: <strong>${nextUpgradeName}</strong> (${houseCost} $)`;
                            } else {
                                this.ui.sheetMonopolyInfo.innerHTML = `👑 <strong style="color: #f59e0b;">MAXIMAL-STUFE ERREICHT!</strong><br>Aktuell: <em>${curLevelName}</em> (Höchste Mietstufe!)`;
                            }
                        } else {
                            const myCount = groupInfo ? (groupInfo.ownerCounts[this.getActivePlayerId()] || 0) : 0;
                            const total = groupInfo ? groupInfo.total : 3;
                            this.ui.sheetMonopolyInfo.innerHTML = `⚠️ <strong style="color: #f59e0b;">Viertel unvollständig (${myCount}/${total})</strong><br>Aktuell: <em>${curLevelName}</em><br>Zum Ausbauen musst du alle ${total} Straßen dieses Viertels besitzen.`;
                        }
                    } else if (owner) {
                        if (groupInfo && groupInfo.isFullMonopoly) {
                            this.ui.sheetMonopolyInfo.innerHTML = `👑 <strong style="color: #ef4444;">Viertel-Monopol von ${owner.name}!</strong><br>Miete verdoppelt! Aktuell: <em>${curLevelName}</em>`;
                        } else {
                            this.ui.sheetMonopolyInfo.innerHTML = `🏠 Im Besitz von ${owner.name}. Aktuell: <em>${curLevelName}</em>`;
                        }
                    } else {
                        this.ui.sheetMonopolyInfo.innerHTML = `🏷️ Dieses Grundstück ist frei und kann beim Daraufladen gekauft werden.`;
                    }
                } else {
                    this.ui.sheetMonopolyInfo.innerHTML = tile.desc || 'Sondergrundstück';
                }
            }

            // Buttons steuern
            const canBuild = (isMine && tile.type === 'street' && groupInfo && groupInfo.isFullMonopoly && (tile.level || 0) < 5 && !tile.mortgaged);
            this.ui.sheetBtnBuild.style.display = canBuild ? 'block' : 'none';
            if (canBuild) {
                const upgradeNames = [
                    'HOLZHÜTTE BAUEN',
                    'EINFAMILIENHAUS BAUEN',
                    'STADTVILLA BAUEN',
                    'LUXUS-RESIDENZ BAUEN',
                    'GRAND SCHLOSS ERRICHTEN'
                ];
                const nextText = upgradeNames[Math.min(tile.level || 0, 4)];
                this.ui.sheetBtnBuild.textContent = `🏗️ ${nextText} (${tile.house_cost || 100} $)`;
            }

            const canSellBuilding = (isMine && tile.type === 'street' && (tile.level || 0) > 1);
            if (this.ui.sheetBtnSellBuilding) {
                this.ui.sheetBtnSellBuilding.style.display = canSellBuilding ? 'block' : 'none';
                if (canSellBuilding) {
                    this.ui.sheetBtnSellBuilding.textContent = `🏚️ STUFE RÜCKBAUEN (+${Math.round((tile.house_cost || 100) / 2)} $)`;
                }
            }

            const canSellProperty = (isMine && (!tile.level || tile.level <= 1));
            if (this.ui.sheetBtnSellProperty) {
                this.ui.sheetBtnSellProperty.style.display = canSellProperty ? 'block' : 'none';
                if (canSellProperty) {
                    const refund = tile.mortgaged ? Math.round(tile.price / 2) : tile.price;
                    this.ui.sheetBtnSellProperty.textContent = `💰 AN BANK VERKAUFEN (+${refund} $)`;
                }
            }

            this.ui.sheetBtnMortgage.style.display = isMine ? 'block' : 'none';
            this.ui.sheetBtnMortgage.textContent = tile.mortgaged ? 'Hypothek ablösen' : 'Mit Hypothek beleihen';

            this.ui.sheetBackdrop.classList.add('open');
        }

        closePropertySheet() {
            this.ui.sheetBackdrop.classList.remove('open');
        }

        openPortfolioModal() {
            this.renderPortfolioModal();
            if (this.ui.modalPortfolio) {
                this.ui.modalPortfolio.classList.add('open');
            }
        }

        closePortfolioModal() {
            if (this.ui.modalPortfolio) {
                this.ui.modalPortfolio.classList.remove('open');
            }
        }

        renderPortfolioModal() {
            if (!this.state || !this.state.tiles) return;

            const sumContainer = document.getElementById('portfolio-players-summary');
            const groupsContainer = document.getElementById('portfolio-groups-list');
            if (!sumContainer || !groupsContainer) return;

            // 1. Spieler-Zusammenfassung oben
            sumContainer.innerHTML = '';
            this.state.players.forEach(p => {
                const ownedTiles = this.state.tiles.filter(t => t.owner === p.id);
                const monopolies = ['docks','suburb','arts','promenade','downtown','marina','heights','millionaires']
                    .filter(g => this.checkGroupOwnership(g)?.monopolyOwnerId === p.id).length;

                const badge = document.createElement('div');
                badge.style.display = 'flex';
                badge.style.alignItems = 'center';
                badge.style.gap = '0.4rem';
                badge.style.padding = '0.35rem 0.65rem';
                badge.style.borderRadius = '8px';
                badge.style.background = 'rgba(15, 23, 42, 0.7)';
                badge.style.border = `2px solid ${p.color}`;
                badge.style.fontSize = '0.8rem';
                badge.innerHTML = `
                    <div style="width: 10px; height: 10px; border-radius: 50%; background: ${p.color};"></div>
                    <strong>${p.name}</strong>: ${p.cash} $
                    <span style="color: var(--hood-muted);">(${ownedTiles.length} Grundstücke${monopolies > 0 ? `, 👑 ${monopolies}` : ''})</span>
                `;
                sumContainer.appendChild(badge);
            });

            // 2. Viertel & Sondergrundstücke auflisten
            groupsContainer.innerHTML = '';
            const groupKeys = ['docks', 'suburb', 'arts', 'promenade', 'downtown', 'marina', 'heights', 'millionaires'];

            const activePlayer = this.getActivePlayer();
            const isMyTurn = this.state.is_hotseat
                ? (activePlayer && !activePlayer.is_bot)
                : (activePlayer && activePlayer.id === this.myPlayerId);
            const activeId = activePlayer ? activePlayer.id : null;

            const levelNames = [
                { lvl: 0, name: 'Unbebaut', icon: '🏷️', color: 'var(--hood-muted)' },
                { lvl: 1, name: 'Holzhütte', icon: '🏕️', color: '#10b981' },
                { lvl: 2, name: 'Einfamilienhaus', icon: '🏡', color: '#38bdf8' },
                { lvl: 3, name: 'Stadtvilla', icon: '🏛️', color: '#a855f7' },
                { lvl: 4, name: 'Luxus-Residenz', icon: '🏰', color: '#ec4899' },
                { lvl: 5, name: 'Grand Schloss & Palais', icon: '👑', color: '#f59e0b' }
            ];

            // A. Die 8 Straßen-Viertel
            groupKeys.forEach(gKey => {
                const gData = this.getGroupData(gKey);
                const gInfo = this.checkGroupOwnership(gKey);
                if (!gInfo) return;

                const card = document.createElement('div');
                card.className = 'hood-group-card';
                card.style.setProperty('--group-color', gData.color);

                const header = document.createElement('div');
                header.className = 'hood-group-header';

                const title = document.createElement('div');
                title.className = 'hood-group-title';
                title.innerHTML = `<span>${gData.badge}</span> <span style="color: ${gData.color}; font-weight:700;">${gData.name}</span>`;

                const badge = document.createElement('div');
                if (gInfo.isFullMonopoly) {
                    const owner = this.state.players.find(p => p.id === gInfo.monopolyOwnerId);
                    badge.className = 'hood-group-monopoly-badge';
                    badge.innerHTML = `👑 Monopol: <span style="color: ${owner ? owner.color : '#fff'}; font-weight: 800;">${owner ? owner.name : ''}</span>`;
                } else {
                    const ownedCount = Object.values(gInfo.ownerCounts).reduce((a, b) => a + b, 0);
                    badge.style.fontSize = '0.75rem';
                    badge.style.color = 'var(--hood-muted)';
                    badge.textContent = `${ownedCount}/${gInfo.total} vergeben`;
                }

                header.appendChild(title);
                header.appendChild(badge);
                card.appendChild(header);

                // Grid der Straßen
                const grid = document.createElement('div');
                grid.className = 'hood-group-tiles-grid';

                gInfo.tiles.forEach(tile => {
                    const owner = this.state.players.find(p => p.id === tile.owner);
                    const isMine = (tile.owner === activeId);
                    const isFullMonopoly = (gInfo.isFullMonopoly && gInfo.monopolyOwnerId === activeId);
                    const curLvl = tile.level || 0;
                    const curInfo = levelNames[Math.min(curLvl, 5)];
                    const houseCost = tile.house_cost || 100;
                    const hasCash = activePlayer ? (activePlayer.cash >= houseCost) : false;
                    const isMortgaged = !!tile.mortgaged;

                    // Button ist NUR aktiv wenn: Eigener Zug + Besitzer + volles Monopol + nicht beliehen + Stufe < 5 + genug Geld
                    const canBuild = isMyTurn && isMine && isFullMonopoly && !isMortgaged && (curLvl < 5) && hasCash;
                    const canSellBuilding = isMyTurn && isMine && (curLvl > 1);

                    const item = document.createElement('div');
                    item.className = 'hood-group-tile-item';

                    const curRent = this.getTileRent(tile);
                    let rentInfoHtml = '';
                    if (!owner) {
                        rentInfoHtml = `<span style="color: var(--hood-muted);">Bei Landung: <strong>0 $</strong> (Frei)</span>`;
                    } else if (isMortgaged) {
                        rentInfoHtml = `<span style="color: #ef4444;">Bei Landung: <strong>0 $</strong> (Hypothek)</span>`;
                    } else if (isMine) {
                        rentInfoHtml = `<span style="color: #22c55e;">Dein Feld (Gegner zahlen <strong>${curRent} $</strong>)</span>`;
                    } else {
                        rentInfoHtml = `<span style="color: #f59e0b;">Bei Landung: <strong style="color: #ef4444; font-size: 0.82rem;">${curRent} $</strong></span>`;
                    }

                    item.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="color: #fff;">${tile.name}</strong>
                            <span style="color: var(--hood-gold); font-weight:700;">${tile.price} $</span>
                        </div>
                        <div class="hood-group-tile-owner" style="margin-top: 0.15rem;">
                            ${owner
                                ? `<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${owner.color};"></span>
                                   <span style="color:${owner.color}; font-weight:600;">${owner.name}</span>`
                                : '<span style="color: var(--hood-muted);">Frei</span>'
                            }
                            ${isMortgaged ? '<span style="color:#ef4444; font-size:0.68rem; margin-left:0.3rem;">[Hypothek]</span>' : ''}
                        </div>

                        <!-- FÄLLIGER BETRAG BEI LANDUNG -->
                        <div style="font-size: 0.72rem; padding: 0.2rem 0.45rem; background: rgba(0, 0, 0, 0.28); border-radius: 4px; margin: 0.3rem 0; display: flex; align-items: center; justify-content: space-between;">
                            ${rentInfoHtml}
                        </div>

                        <!-- SICHTBARE AKTIVE AUSBAUSTUFE (LEVEL 0 bis 5) MIT PROGRESS-BALKEN -->
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.35rem; padding: 0.25rem 0.45rem; background: rgba(15, 23, 42, 0.6); border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.08); margin-bottom: 0.35rem;">
                            <div style="display: flex; align-items: center; gap: 0.35rem;">
                                <span style="font-size: 0.95rem;">${curInfo.icon}</span>
                                <span style="font-size: 0.72rem; font-weight: 700; color: ${curInfo.color};">
                                    ${curLvl === 0 ? 'Stufe 0: Unbebaut' : `Stufe ${curLvl}/5: ${curInfo.name}`}
                                </span>
                            </div>
                            <div style="display: flex; gap: 2px; width: 45px; height: 5px;" title="Aktive Ausbaustufe: ${curLvl} von 5">
                                ${[1, 2, 3, 4, 5].map(step => `
                                    <span style="flex: 1; height: 100%; border-radius: 2px; background: ${curLvl >= step ? (step === 5 ? '#f59e0b' : '#10b981') : 'rgba(255,255,255,0.15)'};"></span>
                                `).join('')}
                            </div>
                        </div>
                    `;

                    // Quick-Action Buttons / Statusanzeigen
                    const actDiv = document.createElement('div');
                    actDiv.className = 'hood-group-tile-actions';
                    actDiv.style.marginTop = '0.35rem';

                    if (isMine) {
                        if (isFullMonopoly) {
                            if (curLvl < 5) {
                                const nextNames = ['', '🏕️ Holzhütte', '🏡 Einfamilienhaus', '🏛️ Stadtvilla', '🏰 Luxus-Residenz', '👑 Grand Schloss'];
                                const nextTitle = nextNames[curLvl + 1] || 'Ausbau';

                                const btnB = document.createElement('button');
                                btnB.type = 'button';

                                if (canBuild) {
                                    btnB.className = 'hood-btn-primary hood-btn-gold';
                                    btnB.style.fontSize = '0.74rem';
                                    btnB.style.padding = '0.35rem 0.5rem';
                                    btnB.textContent = `🏗️ +${nextTitle} (${houseCost} $)`;
                                    btnB.addEventListener('click', async () => {
                                        const res = await this.apiPost('build', { tile_index: tile.index });
                                        if (res.success) {
                                            this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                                            this.renderPortfolioModal();
                                        } else {
                                            this.showToast(res.message || 'Ausbau nicht möglich', 'error');
                                        }
                                    });
                                } else {
                                    btnB.className = 'hood-btn-primary hood-btn-secondary';
                                    btnB.disabled = true;
                                    btnB.style.opacity = '0.45';
                                    btnB.style.cursor = 'not-allowed';
                                    btnB.style.fontSize = '0.72rem';
                                    btnB.style.padding = '0.3rem 0.45rem';

                                    if (!isMyTurn) {
                                        btnB.textContent = `🏗️ +${nextTitle} (${houseCost} $) • Nicht am Zug`;
                                    } else if (!hasCash) {
                                        btnB.textContent = `🏗️ +${nextTitle} (${houseCost} $) • Zu wenig Geld`;
                                    } else if (isMortgaged) {
                                        btnB.textContent = `⚠️ Hypothek aktiv`;
                                    }
                                }
                                actDiv.appendChild(btnB);
                            } else {
                                const maxBadge = document.createElement('div');
                                maxBadge.style.fontSize = '0.72rem';
                                maxBadge.style.fontWeight = '800';
                                maxBadge.style.color = '#f59e0b';
                                maxBadge.style.textAlign = 'center';
                                maxBadge.style.padding = '0.2rem';
                                maxBadge.textContent = '👑 MAXIMAL-STUFE ERREICHT';
                                actDiv.appendChild(maxBadge);
                            }

                            if (canSellBuilding) {
                                const btnS = document.createElement('button');
                                btnS.type = 'button';
                                btnS.className = 'hood-btn-primary hood-btn-secondary';
                                btnS.style.fontSize = '0.72rem';
                                btnS.style.padding = '0.25rem 0.45rem';
                                btnS.textContent = `🏚️ Rückbau (-50%)`;
                                btnS.addEventListener('click', async () => {
                                    const res = await this.apiPost('sell_building', { tile_index: tile.index });
                                    if (res.success) {
                                        this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                                        this.renderPortfolioModal();
                                    } else {
                                        this.showToast(res.message || 'Verkauf nicht möglich', 'error');
                                    }
                                });
                                actDiv.appendChild(btnS);
                            } else if (isMyTurn && curLvl <= 1) {
                                const mortgageVal = Math.floor(tile.price / 2);
                                const repayCost = Math.ceil(mortgageVal * 1.10);
                                const btnM = document.createElement('button');
                                btnM.type = 'button';
                                btnM.className = 'hood-btn-primary hood-btn-secondary';
                                btnM.style.fontSize = '0.72rem';
                                btnM.style.padding = '0.25rem 0.45rem';
                                if (isMortgaged) {
                                    btnM.textContent = `🏦 Ablösen (${repayCost} $)`;
                                    btnM.style.borderColor = '#38bdf8';
                                    btnM.style.color = '#38bdf8';
                                } else {
                                    btnM.textContent = `🏦 Beleihen (+${mortgageVal} $)`;
                                }
                                btnM.addEventListener('click', () => this.actionMortgage(tile.index));
                                actDiv.appendChild(btnM);
                            }
                        } else {
                            const uncompleteNote = document.createElement('div');
                            uncompleteNote.style.fontSize = '0.7rem';
                            uncompleteNote.style.color = 'var(--hood-muted)';
                            uncompleteNote.style.textAlign = 'center';
                            const myCount = gInfo.ownerCounts[activeId] || 0;
                            uncompleteNote.textContent = `⚠️ Viertel unvollständig (${myCount}/${gInfo.total})`;
                            actDiv.appendChild(uncompleteNote);

                            if (isMyTurn && curLvl <= 1) {
                                const mortgageVal = Math.floor(tile.price / 2);
                                const repayCost = Math.ceil(mortgageVal * 1.10);
                                const btnM = document.createElement('button');
                                btnM.type = 'button';
                                btnM.className = 'hood-btn-primary hood-btn-secondary';
                                btnM.style.fontSize = '0.72rem';
                                btnM.style.padding = '0.25rem 0.45rem';
                                btnM.style.marginTop = '0.25rem';
                                if (isMortgaged) {
                                    btnM.textContent = `🏦 Ablösen (${repayCost} $)`;
                                    btnM.style.borderColor = '#38bdf8';
                                    btnM.style.color = '#38bdf8';
                                } else {
                                    btnM.textContent = `🏦 Beleihen (+${mortgageVal} $)`;
                                }
                                btnM.addEventListener('click', () => this.actionMortgage(tile.index));
                                actDiv.appendChild(btnM);
                            }
                        }
                    } else if (owner && (!activeId || owner.id !== activeId)) {
                        // Gehört einem Mitspieler -> Angebot machen Button
                        const btnTrade = document.createElement('button');
                        btnTrade.type = 'button';
                        btnTrade.className = 'hood-btn-primary hood-btn-secondary';
                        btnTrade.style.fontSize = '0.72rem';
                        btnTrade.style.padding = '0.25rem 0.5rem';
                        btnTrade.style.borderRadius = '9999px';
                        btnTrade.textContent = `🤝 Angebot an ${owner.name}`;
                        btnTrade.addEventListener('click', () => {
                            this.closePortfolioModal();
                            this.openTradeModal(owner.id);
                        });
                        actDiv.appendChild(btnTrade);
                    }

                    if (actDiv.children.length > 0) {
                        item.appendChild(actDiv);
                    }

                    grid.appendChild(item);
                });

                card.appendChild(grid);
                groupsContainer.appendChild(card);
            });

            // B. KÜSTENSTRÄNDE (Feld 5, 15, 25, 35)
            const beachIndices = [5, 15, 25, 35];
            const beachTiles = beachIndices.map(idx => this.state.tiles[idx]).filter(Boolean);
            if (beachTiles.length > 0) {
                const cardB = document.createElement('div');
                cardB.className = 'hood-group-card';
                cardB.style.setProperty('--group-color', '#0284c7');

                const headerB = document.createElement('div');
                headerB.className = 'hood-group-header';

                const titleB = document.createElement('div');
                titleB.className = 'hood-group-title';
                titleB.innerHTML = `<span>🏖️</span> <span style="color: #38bdf8; font-weight:700;">Küstenstrände & Strandbäder</span>`;

                const ownedBeaches = beachTiles.filter(t => t.owner !== null);
                const beachOwnerCounts = {};
                beachTiles.forEach(t => {
                    if (t.owner) beachOwnerCounts[t.owner] = (beachOwnerCounts[t.owner] || 0) + 1;
                });
                const monopolyBeachOwnerId = Object.keys(beachOwnerCounts).find(oId => beachOwnerCounts[oId] === 4);

                const badgeB = document.createElement('div');
                if (monopolyBeachOwnerId) {
                    const mOwner = this.state.players.find(p => p.id === parseInt(monopolyBeachOwnerId, 10));
                    badgeB.className = 'hood-group-monopoly-badge';
                    badgeB.innerHTML = `👑 Alle 4 Strände: <span style="color: ${mOwner ? mOwner.color : '#fff'}; font-weight: 800;">${mOwner ? mOwner.name : ''}</span> (200 $)`;
                } else {
                    badgeB.style.fontSize = '0.75rem';
                    badgeB.style.color = 'var(--hood-muted)';
                    badgeB.textContent = `${ownedBeaches.length}/4 vergeben`;
                }

                headerB.appendChild(titleB);
                headerB.appendChild(badgeB);
                cardB.appendChild(headerB);

                const gridB = document.createElement('div');
                gridB.className = 'hood-group-tiles-grid';

                beachTiles.forEach(tile => {
                    const owner = this.state.players.find(p => p.id === tile.owner);
                    const ownerBeachCount = owner ? (beachOwnerCounts[owner.id] || 0) : 0;
                    const rentScale = [25, 50, 100, 200];
                    const curRent = ownerBeachCount > 0 ? rentScale[ownerBeachCount - 1] : 25;

                    const curBeachRent = this.getTileRent(tile);
                    let rentBeachHtml = '';
                    if (!owner) {
                        rentBeachHtml = `<span style="color: var(--hood-muted);">Bei Landung: <strong>0 $</strong> (Frei)</span>`;
                    } else if (tile.mortgaged) {
                        rentBeachHtml = `<span style="color: #ef4444;">Bei Landung: <strong>0 $</strong> (Hypothek)</span>`;
                    } else if (owner.id === activeId) {
                        rentBeachHtml = `<span style="color: #22c55e;">Dein Strand (Gegner zahlen <strong>${curBeachRent} $</strong>)</span>`;
                    } else {
                        rentBeachHtml = `<span style="color: #f59e0b;">Bei Landung: <strong style="color: #ef4444; font-size: 0.82rem;">${curBeachRent} $</strong></span>`;
                    }

                    const itemB = document.createElement('div');
                    itemB.className = 'hood-group-tile-item';

                    itemB.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="color: #fff;">🏖️ ${tile.name}</strong>
                            <span style="color: var(--hood-gold); font-weight:700;">${tile.price} $</span>
                        </div>
                        <div class="hood-group-tile-owner" style="margin-top: 0.15rem;">
                            ${owner
                                ? `<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${owner.color};"></span>
                                   <span style="color:${owner.color}; font-weight:600;">${owner.name}</span> (${ownerBeachCount}/4 Strände)`
                                : '<span style="color: var(--hood-muted);">Frei</span>'
                            }
                            ${tile.mortgaged ? '<span style="color:#ef4444; font-size:0.68rem; margin-left:0.3rem;">[Hypothek]</span>' : ''}
                        </div>
                        <div style="font-size: 0.72rem; padding: 0.2rem 0.45rem; background: rgba(0, 0, 0, 0.28); border-radius: 4px; margin: 0.35rem 0; display: flex; align-items: center; justify-content: space-between;">
                            ${rentBeachHtml}
                        </div>
                    `;

                    if (owner && activeId && owner.id === activeId && isMyTurn) {
                        const actB = document.createElement('div');
                        actB.style.marginTop = '0.35rem';
                        const mortgageVal = Math.floor(tile.price / 2);
                        const repayCost = Math.ceil(mortgageVal * 1.10);
                        const btnM = document.createElement('button');
                        btnM.type = 'button';
                        btnM.className = 'hood-btn-primary hood-btn-secondary';
                        btnM.style.fontSize = '0.72rem';
                        btnM.style.padding = '0.25rem 0.45rem';
                        btnM.style.width = '100%';
                        if (tile.mortgaged) {
                            btnM.textContent = `🏦 Ablösen (${repayCost} $)`;
                            btnM.style.borderColor = '#38bdf8';
                            btnM.style.color = '#38bdf8';
                        } else {
                            btnM.textContent = `🏦 Beleihen (+${mortgageVal} $)`;
                        }
                        btnM.addEventListener('click', () => this.actionMortgage(tile.index));
                        actB.appendChild(btnM);
                        itemB.appendChild(actB);
                    } else if (owner && (!activeId || owner.id !== activeId)) {
                        const actB = document.createElement('div');
                        actB.style.marginTop = '0.35rem';
                        const btnT = document.createElement('button');
                        btnT.type = 'button';
                        btnT.className = 'hood-btn-primary hood-btn-secondary';
                        btnT.style.fontSize = '0.72rem';
                        btnT.style.padding = '0.25rem 0.5rem';
                        btnT.style.borderRadius = '9999px';
                        btnT.textContent = `🤝 Angebot an ${owner.name}`;
                        btnT.addEventListener('click', () => {
                            this.closePortfolioModal();
                            this.openTradeModal(owner.id);
                        });
                        actB.appendChild(btnT);
                        itemB.appendChild(actB);
                    }

                    gridB.appendChild(itemB);
                });

                cardB.appendChild(gridB);
                groupsContainer.appendChild(cardB);
            }

            // C. NIGHTLIFE: BARS & CLUBS (Feld 12, 28)
            const clubIndices = [12, 28];
            const clubTiles = clubIndices.map(idx => this.state.tiles[idx]).filter(Boolean);
            if (clubTiles.length > 0) {
                const cardC = document.createElement('div');
                cardC.className = 'hood-group-card';
                cardC.style.setProperty('--group-color', '#a855f7');

                const headerC = document.createElement('div');
                headerC.className = 'hood-group-header';

                const titleC = document.createElement('div');
                titleC.className = 'hood-group-title';
                titleC.innerHTML = `<span>🍸</span> <span style="color: #c084fc; font-weight:700;">Nightlife: Bars & Lounges</span>`;

                const ownedClubs = clubTiles.filter(t => t.owner !== null);
                const clubOwnerCounts = {};
                clubTiles.forEach(t => {
                    if (t.owner) clubOwnerCounts[t.owner] = (clubOwnerCounts[t.owner] || 0) + 1;
                });
                const monopolyClubOwnerId = Object.keys(clubOwnerCounts).find(oId => clubOwnerCounts[oId] === 2);

                const badgeC = document.createElement('div');
                if (monopolyClubOwnerId) {
                    const mOwner = this.state.players.find(p => p.id === parseInt(monopolyClubOwnerId, 10));
                    badgeC.className = 'hood-group-monopoly-badge';
                    badgeC.innerHTML = `👑 Beide Clubs: <span style="color: ${mOwner ? mOwner.color : '#fff'}; font-weight: 800;">${mOwner ? mOwner.name : ''}</span> (10x Wurf)`;
                } else {
                    badgeC.style.fontSize = '0.75rem';
                    badgeC.style.color = 'var(--hood-muted)';
                    badgeC.textContent = `${ownedClubs.length}/2 vergeben`;
                }

                headerC.appendChild(titleC);
                headerC.appendChild(badgeC);
                cardC.appendChild(headerC);

                const gridC = document.createElement('div');
                gridC.className = 'hood-group-tiles-grid';

                clubTiles.forEach(tile => {
                    const owner = this.state.players.find(p => p.id === tile.owner);
                    const ownerClubCount = owner ? (clubOwnerCounts[owner.id] || 0) : 0;
                    const multiplierText = (ownerClubCount >= 2) ? '10x Augenwert' : '4x Augenwert';

                    let rentClubHtml = '';
                    if (!owner) {
                        rentClubHtml = `<span style="color: var(--hood-muted);">Bei Landung: <strong>0 $</strong> (Frei)</span>`;
                    } else if (tile.mortgaged) {
                        rentClubHtml = `<span style="color: #ef4444;">Bei Landung: <strong>0 $</strong> (Hypothek)</span>`;
                    } else if (owner.id === activeId) {
                        rentClubHtml = `<span style="color: #22c55e;">Dein Club (Gegner zahlen <strong>${multiplierText}</strong>)</span>`;
                    } else {
                        rentClubHtml = `<span style="color: #f59e0b;">Bei Landung: <strong style="color: #ef4444; font-size: 0.82rem;">${multiplierText}</strong></span>`;
                    }

                    const itemC = document.createElement('div');
                    itemC.className = 'hood-group-tile-item';

                    itemC.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="color: #fff;">🍸 ${tile.name}</strong>
                            <span style="color: var(--hood-gold); font-weight:700;">${tile.price} $</span>
                        </div>
                        <div class="hood-group-tile-owner" style="margin-top: 0.15rem;">
                            ${owner
                                ? `<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${owner.color};"></span>
                                   <span style="color:${owner.color}; font-weight:600;">${owner.name}</span> (${ownerClubCount}/2 Clubs)`
                                : '<span style="color: var(--hood-muted);">Frei</span>'
                            }
                            ${tile.mortgaged ? '<span style="color:#ef4444; font-size:0.68rem; margin-left:0.3rem;">[Hypothek]</span>' : ''}
                        </div>
                        <div style="font-size: 0.72rem; padding: 0.2rem 0.45rem; background: rgba(0, 0, 0, 0.28); border-radius: 4px; margin: 0.35rem 0; display: flex; align-items: center; justify-content: space-between;">
                            ${rentClubHtml}
                        </div>
                    `;

                    if (owner && activeId && owner.id === activeId && isMyTurn) {
                        const actB = document.createElement('div');
                        actB.style.marginTop = '0.35rem';
                        const mortgageVal = Math.floor(tile.price / 2);
                        const repayCost = Math.ceil(mortgageVal * 1.10);
                        const btnM = document.createElement('button');
                        btnM.type = 'button';
                        btnM.className = 'hood-btn-primary hood-btn-secondary';
                        btnM.style.fontSize = '0.72rem';
                        btnM.style.padding = '0.25rem 0.45rem';
                        btnM.style.width = '100%';
                        if (tile.mortgaged) {
                            btnM.textContent = `🏦 Ablösen (${repayCost} $)`;
                            btnM.style.borderColor = '#38bdf8';
                            btnM.style.color = '#38bdf8';
                        } else {
                            btnM.textContent = `🏦 Beleihen (+${mortgageVal} $)`;
                        }
                        btnM.addEventListener('click', () => this.actionMortgage(tile.index));
                        actB.appendChild(btnM);
                        itemB.appendChild(actB);
                    } else if (owner && (!activeId || owner.id !== activeId)) {
                        const actC = document.createElement('div');
                        actC.style.marginTop = '0.35rem';
                        const btnT = document.createElement('button');
                        btnT.type = 'button';
                        btnT.className = 'hood-btn-primary hood-btn-secondary';
                        btnT.style.fontSize = '0.72rem';
                        btnT.style.padding = '0.25rem 0.5rem';
                        btnT.style.borderRadius = '9999px';
                        btnT.textContent = `🤝 Angebot an ${owner.name}`;
                        btnT.addEventListener('click', () => {
                            this.closePortfolioModal();
                            this.openTradeModal(owner.id);
                        });
                        actC.appendChild(btnT);
                        itemC.appendChild(actC);
                    }

                    gridC.appendChild(itemC);
                });

                cardC.appendChild(gridC);
                groupsContainer.appendChild(cardC);
            }
        }

        // ---------------------------------------------------------------------
        // 11b. HANDELSSYSTEM (SPIELER-ZU-SPIELER / KI-HANDEL)
        // ---------------------------------------------------------------------
        openTradeModal(partnerId = null) {
            if (!this.state || !this.state.players) return;
            const senderId = this.getActivePlayerId();
            if (partnerId === senderId) partnerId = null;
            this.selectedTradePartnerId = partnerId;
            this.renderTradePartners();
            this.renderTradeTiles();
            if (this.ui.modalTrade) {
                this.ui.modalTrade.classList.add('open');
            }
        }

        closeTradeModal() {
            if (this.ui.modalTrade) {
                this.ui.modalTrade.classList.remove('open');
            }
        }

        renderTradePartners() {
            const container = document.getElementById('trade-partners-list');
            if (!container || !this.state) return;
            container.innerHTML = '';

            const senderId = this.getActivePlayerId();
            const partners = this.state.players.filter(p => p.id !== senderId && !p.bankrupt);
            if (partners.length === 0) {
                container.innerHTML = '<span style="font-size:0.8rem; color:var(--hood-muted);">Keine Handelspartner verfügbar.</span>';
                return;
            }

            if (!this.selectedTradePartnerId || this.selectedTradePartnerId === senderId || !partners.some(p => p.id === this.selectedTradePartnerId)) {
                this.selectedTradePartnerId = partners[0].id;
            }

            partners.forEach(p => {
                const btn = document.createElement('button');
                btn.type = 'button';
                const isSelected = (p.id === this.selectedTradePartnerId);
                btn.className = isSelected ? 'hood-btn-primary hood-btn-gold' : 'hood-btn-primary hood-btn-secondary';
                btn.style.fontSize = '0.78rem';
                btn.style.padding = '0.35rem 0.65rem';
                btn.style.borderRadius = '9999px';
                btn.innerHTML = `<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${p.color};"></span> ${p.name} ${p.is_bot ? '(KI)' : ''}`;
                btn.addEventListener('click', () => {
                    this.selectedTradePartnerId = p.id;
                    this.renderTradePartners();
                    this.renderTradeTiles();
                });
                container.appendChild(btn);
            });
        }

        renderTradeTiles() {
            const myTilesContainer = document.getElementById('trade-my-tiles');
            const theirTilesContainer = document.getElementById('trade-their-tiles');
            if (!myTilesContainer || !theirTilesContainer || !this.state) return;

            const senderId = this.getActivePlayerId();
            const sender = this.state.players.find(p => p.id === senderId);
            const partner = this.state.players.find(p => p.id === this.selectedTradePartnerId);

            // Dynamische Labels je nach Spielmodus & aktivem Spieler
            const titleEl = document.getElementById('trade-modal-title');
            const descEl = document.getElementById('trade-modal-desc');
            const offerLabel = document.getElementById('trade-offer-label');
            const requestLabel = document.getElementById('trade-request-label');
            const offerCashMax = document.getElementById('trade-offer-available-cash');
            const requestCashMax = document.getElementById('trade-request-available-cash');

            if (this.state.is_hotseat && sender) {
                if (titleEl) titleEl.textContent = `🤝 Handel vorschlagen • ${sender.name}`;
                if (descEl) descEl.innerHTML = `🛋️ <strong>${sender.name}</strong> verhandelt mit Mitspielern oder KI-Fahrern:`;
                if (offerLabel) offerLabel.textContent = `Angebot von ${sender.name}`;
            } else {
                if (titleEl) titleEl.textContent = '🤝 Handel vorschlagen';
                if (descEl) descEl.textContent = 'Tausche Grundstücke und Geld mit Mitspielern oder KI-Fahrern, um Monopole zu vervollständigen!';
                if (offerLabel) offerLabel.textContent = 'Dein Angebot';
            }

            if (partner && requestLabel) {
                requestLabel.textContent = `Gegenleistung von ${partner.name}`;
            } else if (requestLabel) {
                requestLabel.textContent = 'Gegenleistung';
            }

            // Bargeld-Limits anzeigen
            if (offerCashMax && sender) {
                offerCashMax.textContent = `(max. ${sender.cash} $)`;
            }
            if (requestCashMax && partner) {
                requestCashMax.textContent = `(max. ${partner.cash} $)`;
            }

            const offerCashInput = document.getElementById('trade-offer-cash');
            if (offerCashInput && sender) {
                offerCashInput.max = sender.cash;
            }
            const requestCashInput = document.getElementById('trade-request-cash');
            if (requestCashInput && partner) {
                requestCashInput.max = partner.cash;
            }

            // Hilfsfunktion zur Darstellung einer Grundstücks-Zeile im Handel (Straßen, Strände, Bars)
            const createTradeTileRow = (t, chkClass) => {
                const row = document.createElement('label');
                row.style.display = 'flex';
                row.style.alignItems = 'center';
                row.style.gap = '0.45rem';
                row.style.fontSize = '0.78rem';
                row.style.cursor = 'pointer';
                row.style.padding = '0.3rem 0.45rem';
                row.style.borderRadius = '6px';
                row.style.background = 'rgba(255, 255, 255, 0.04)';
                row.style.border = '1px solid rgba(255, 255, 255, 0.08)';

                let badgeIcon = '🏡';
                let badgeColor = '#fff';
                let typeBadge = '';

                if (t.type === 'beach') {
                    badgeIcon = '🏖️';
                    badgeColor = '#38bdf8';
                    typeBadge = 'Strand';
                } else if (t.type === 'club') {
                    badgeIcon = '🍸';
                    badgeColor = '#c084fc';
                    typeBadge = 'Bar / Club';
                } else {
                    const gData = this.getGroupData(t.group);
                    badgeIcon = gData.badge;
                    badgeColor = gData.color;
                    typeBadge = gData.name;
                }

                row.innerHTML = `
                    <input type="checkbox" class="${chkClass}" value="${t.index}">
                    <span style="font-size: 0.95rem;">${badgeIcon}</span>
                    <span style="color:#fff; font-weight: 600;">${t.name}</span>
                    <span style="font-size: 0.68rem; color: ${badgeColor}; margin-left: auto;">${typeBadge} (${t.price} $)</span>
                `;
                return row;
            };

            // Grundstücke des aktiven Spielers (Straßen mit Level <= 1, Strände und Bars)
            myTilesContainer.innerHTML = '';
            const myTiles = this.state.tiles.filter(t => t.owner === senderId && (t.level || 0) <= 1 && (t.type === 'street' || t.type === 'beach' || t.type === 'club'));
            if (myTiles.length === 0) {
                myTilesContainer.innerHTML = `<span style="font-size:0.75rem; color:var(--hood-muted);">${sender ? sender.name : 'Du'} besitzt keine handelbaren Grundstücke, Strände oder Bars</span>`;
            } else {
                myTiles.forEach(t => {
                    myTilesContainer.appendChild(createTradeTileRow(t, 'trade-offer-chk'));
                });
            }

            // Partner-Grundstücke
            theirTilesContainer.innerHTML = '';
            if (!partner) {
                theirTilesContainer.innerHTML = '<span style="font-size:0.75rem; color:var(--hood-muted);">Partner auswählen...</span>';
                return;
            }

            const theirTiles = this.state.tiles.filter(t => t.owner === partner.id && (t.level || 0) <= 1 && (t.type === 'street' || t.type === 'beach' || t.type === 'club'));
            if (theirTiles.length === 0) {
                theirTilesContainer.innerHTML = `<span style="font-size:0.75rem; color:var(--hood-muted);">${partner.name} besitzt keine handelbaren Grundstücke, Strände oder Bars</span>`;
            } else {
                theirTiles.forEach(t => {
                    theirTilesContainer.appendChild(createTradeTileRow(t, 'trade-request-chk'));
                });
            }
        }

        async submitTrade() {
            if (!this.selectedTradePartnerId) {
                this.showToast('Bitte wähle zuerst einen Handelspartner aus.', 'warning');
                return;
            }

            const senderId = this.getActivePlayerId();
            const sender = this.state.players.find(p => p.id === senderId);
            const partner = this.state.players.find(p => p.id === this.selectedTradePartnerId);

            const offerChks = document.querySelectorAll('.trade-offer-chk:checked');
            const offerTiles = Array.from(offerChks).map(c => parseInt(c.value, 10));
            const offerCashInput = document.getElementById('trade-offer-cash');
            const offerCash = offerCashInput ? parseInt(offerCashInput.value, 10) || 0 : 0;

            const requestChks = document.querySelectorAll('.trade-request-chk:checked');
            const requestTiles = Array.from(requestChks).map(c => parseInt(c.value, 10));
            const requestCashInput = document.getElementById('trade-request-cash');
            const requestCash = requestCashInput ? parseInt(requestCashInput.value, 10) || 0 : 0;

            if (offerTiles.length === 0 && offerCash <= 0) {
                this.showToast('Du musst mindestens ein Grundstück oder Bargeld anbieten.', 'warning');
                return;
            }
            if (requestTiles.length === 0 && requestCash <= 0) {
                this.showToast('Du musst mindestens ein Grundstück oder Bargeld als Gegenleistung fordern.', 'warning');
                return;
            }

            if (offerCash > (sender ? sender.cash : 0)) {
                this.showToast(`${sender ? sender.name : 'Du'} hast nur ${sender ? sender.cash : 0} $ Bargeld zur Verfügung.`, 'warning');
                return;
            }
            if (requestCash > (partner ? partner.cash : 0)) {
                this.showToast(`${partner ? partner.name : 'Der Partner'} hat nur ${partner ? partner.cash : 0} $ Bargeld zur Verfügung.`, 'warning');
                return;
            }

            const res = await this.apiPost('propose_trade', {
                from_player_id: senderId,
                to_player_id: this.selectedTradePartnerId,
                offer_tiles: offerTiles,
                offer_cash: offerCash,
                request_tiles: requestTiles,
                request_cash: requestCash
            });

            if (res.success) {
                this.closeTradeModal();
                this.showToast(res.message || 'Handelsangebot übermittelt!', 'success');
                if (res.state) {
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                }
            } else {
                this.showToast(res.message || 'Handel konnte nicht vorgeschlagen werden.', 'error');
            }
        }

        renderIncomingTradeModal(trade) {
            if (!trade || !this.state) return;
            const fromPlayer = this.state.players.find(p => p.id === trade.from_id);
            const toPlayer = this.state.players.find(p => p.id === trade.to_id);

            const title = document.getElementById('incoming-trade-title');
            const subtitle = document.getElementById('incoming-trade-subtitle');
            const recLabel = document.getElementById('incoming-trade-receive-label');
            const giveLabel = document.getElementById('incoming-trade-give-label');
            const recContainer = document.getElementById('incoming-trade-receive');
            const giveContainer = document.getElementById('incoming-trade-give');
            const btnAccept = document.getElementById('btn-accept-trade');

            if (title) {
                if (this.state.is_hotseat) {
                    title.textContent = `🤝 Angebot von ${fromPlayer ? fromPlayer.name : 'Fahrer'} an ${toPlayer ? toPlayer.name : 'Mitspieler'}`;
                } else {
                    title.textContent = `🤝 Angebot von ${fromPlayer ? fromPlayer.name : 'Mitspieler'}`;
                }
            }

            if (subtitle) {
                if (this.state.is_hotseat) {
                    subtitle.textContent = `${toPlayer ? toPlayer.name : 'Spieler'}, möchtest du diesen Deal von ${fromPlayer ? fromPlayer.name : 'Fahrer'} annehmen?`;
                } else {
                    subtitle.textContent = `${fromPlayer ? fromPlayer.name : 'Ein Fahrer'} bietet dir folgenden Deal an:`;
                }
            }

            if (recLabel && toPlayer) {
                recLabel.textContent = `${toPlayer.name} erhält:`;
            } else if (recLabel) {
                recLabel.textContent = 'Du erhältst:';
            }

            if (giveLabel && toPlayer) {
                giveLabel.textContent = `${toPlayer.name} gibt ab:`;
            } else if (giveLabel) {
                giveLabel.textContent = 'Du gibst ab:';
            }

            if (btnAccept && this.state.is_hotseat && toPlayer) {
                btnAccept.textContent = `✅ DEAL ANNEHMEN (${toPlayer.name})`;
            } else if (btnAccept) {
                btnAccept.textContent = '✅ DEAL ANNEHMEN';
            }

            if (recContainer) {
                recContainer.innerHTML = '';
                if (trade.offer_cash > 0) {
                    const cDiv = document.createElement('div');
                    cDiv.innerHTML = `💵 <strong>${trade.offer_cash} $</strong> Bargeld`;
                    recContainer.appendChild(cDiv);
                }
                trade.offer_tiles.forEach(tIdx => {
                    const t = this.state.tiles[tIdx];
                    if (!t) return;
                    const gData = this.getGroupData(t.group);
                    const div = document.createElement('div');
                    div.innerHTML = `🏡 <span style="color:${gData ? gData.color : '#fff'}; font-weight:700;">${t.name}</span> (${t.price} $)`;
                    recContainer.appendChild(div);
                });
                if (trade.offer_tiles.length === 0 && trade.offer_cash <= 0) {
                    recContainer.textContent = 'Nichts';
                }
            }

            if (giveContainer) {
                giveContainer.innerHTML = '';
                if (trade.request_cash > 0) {
                    const cDiv = document.createElement('div');
                    cDiv.innerHTML = `💵 <strong>${trade.request_cash} $</strong> Bargeld`;
                    giveContainer.appendChild(cDiv);
                }
                trade.request_tiles.forEach(tIdx => {
                    const t = this.state.tiles[tIdx];
                    if (!t) return;
                    const gData = this.getGroupData(t.group);
                    const div = document.createElement('div');
                    div.innerHTML = `🏡 <span style="color:${gData ? gData.color : '#fff'}; font-weight:700;">${t.name}</span> (${t.price} $)`;
                    giveContainer.appendChild(div);
                });
                if (trade.request_tiles.length === 0 && trade.request_cash <= 0) {
                    giveContainer.textContent = 'Nichts';
                }
            }

            if (this.ui.modalTradeIncoming) {
                this.ui.modalTradeIncoming.classList.add('open');
            }
        }

        async respondTrade(accept) {
            const res = await this.apiPost('respond_trade', { accept: !!accept });
            if (this.ui.modalTradeIncoming) {
                this.ui.modalTradeIncoming.classList.remove('open');
            }
            if (res.success && res.state) {
                this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
            } else if (!res.success) {
                this.showToast(res.message || 'Antwort fehlgeschlagen', 'error');
            }
        }

        // ---------------------------------------------------------------------
        // 12. LOBBY
        // ---------------------------------------------------------------------
        showLobbyModal(isCreating) {
            this.ui.lobbyModal.classList.add('open');
            const formCreate = document.getElementById('lobby-create-form');
            const formJoin = document.getElementById('lobby-join-form');
            const formWaiting = document.getElementById('lobby-waiting-room');

            if (formCreate) formCreate.style.display = isCreating ? 'block' : 'none';
            if (formJoin) formJoin.style.display = 'none';
            if (formWaiting) formWaiting.style.display = 'none';

            const nameInput = document.getElementById('input-player-name');
            const savedName = localStorage.getItem('kai_gh_player_name');
            if (nameInput && savedName) {
                nameInput.value = savedName;
            }
            if (nameInput && !nameInput._hoodBound) {
                nameInput._hoodBound = true;
                const saveName = () => {
                    const val = nameInput.value.trim();
                    if (val) {
                        localStorage.setItem('kai_gh_player_name', val);
                    }
                };
                nameInput.addEventListener('input', saveName);
                nameInput.addEventListener('change', saveName);
            }

            const pinInput = document.getElementById('input-pin');
            const pinBadge = document.getElementById('pin-saved-badge');

            const updateSavedPinField = () => {
                const savedPin = localStorage.getItem('kai_gh_host_pin') || '';
                if (pinInput) {
                    if (savedPin) {
                        pinInput.value = savedPin;
                        if (pinBadge) pinBadge.classList.remove('hidden');
                    } else {
                        if (pinBadge) pinBadge.classList.add('hidden');
                    }
                }
            };

            if (pinInput && !pinInput._hoodBound) {
                pinInput._hoodBound = true;
                pinInput.addEventListener('input', () => {
                    const savedPin = localStorage.getItem('kai_gh_host_pin') || '';
                    if (pinBadge) {
                        if (pinInput.value && pinInput.value === savedPin) {
                            pinBadge.classList.remove('hidden');
                        } else {
                            pinBadge.classList.add('hidden');
                        }
                    }
                });
            }

            updateSavedPinField();

            let chosenMode = 'hotseat';
            const tabHotSeat = document.getElementById('mode-tab-hotseat');
            const tabOnline = document.getElementById('mode-tab-online');

            if (tabHotSeat && tabOnline && !tabHotSeat._hoodBound) {
                tabHotSeat._hoodBound = true;
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
                if (swatch._hoodBound) return;
                swatch._hoodBound = true;
                swatch.addEventListener('click', (e) => {
                    colorSwatches.forEach(s => s.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            const vehicleCards = document.querySelectorAll('.hood-vehicle-card');
            vehicleCards.forEach(card => {
                if (card._hoodBound) return;
                card._hoodBound = true;
                card.addEventListener('click', (e) => {
                    vehicleCards.forEach(c => c.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            const btnCreate = document.getElementById('btn-create-room');
            if (btnCreate && !btnCreate._hoodBound) {
                btnCreate._hoodBound = true;
                btnCreate.addEventListener('click', async () => {
                    const name = (nameInput ? nameInput.value.trim() : '') || 'Driver';
                    const pin = pinInput ? pinInput.value.trim() : '';
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
                        if (name) {
                            localStorage.setItem('kai_gh_player_name', name);
                        }
                        if (pin) {
                            localStorage.setItem('kai_gh_host_pin', pin);
                        }
                        this.roomCode = data.room_code;
                        this.playerToken = data.player_token;
                        localStorage.setItem('quarxl_hood_token_' + this.roomCode, this.playerToken);
                        window.history.replaceState({}, '', `?room=${this.roomCode}`);
                        this.applyState(data.state, data.state.players[0]);
                        this.startPolling();
                    } else {
                        if (res.status === 403 || (data.message && data.message.toLowerCase().includes('pin'))) {
                            localStorage.removeItem('kai_gh_host_pin');
                            updateSavedPinField();
                        }
                        this.showToast(data.message || 'Konnte Raum nicht anlegen', 'error');
                    }
                });
            }

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
                if (this.state && this.state.players && this.state.players.length < 2) {
                    this.showToast('Mindestens 2 Spieler oder KI-Fahrer erforderlich, um zu starten!', 'warning');
                    return;
                }
                this.audio.playHorn();
                const res = await this.apiPost('start');
                if (res.success) {
                    this.applyState(res.state, res.state.players.find(p => p.id === this.myPlayerId));
                } else {
                    this.showToast(res.message || 'Start fehlgeschlagen', 'error');
                }
            });
        }

        renderLobbyModal(state) {
            this.ui.lobbyModal.classList.add('open');
            const formCreate = document.getElementById('lobby-create-form');
            const formJoin = document.getElementById('lobby-join-form');
            const formWaiting = document.getElementById('lobby-waiting-room');

            if (formCreate) formCreate.style.display = 'none';
            if (formJoin) formJoin.style.display = 'none';
            if (formWaiting) formWaiting.style.display = 'block';

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
                    <span style="color: var(--hood-muted); font-size: 0.8rem;">(${p.is_bot ? 'KI' : (p.is_host ? 'Host' : 'Fahrer')})</span>
                `;
                list.appendChild(li);
            });

            document.getElementById('btn-add-bot').style.display = this.isHost ? 'block' : 'none';
            
            const btnStart = document.getElementById('btn-start-game');
            const hintMin = document.getElementById('lobby-min-player-hint');
            const hasEnoughPlayers = (state.players && state.players.length >= 2);
            if (btnStart) {
                btnStart.style.display = this.isHost ? 'block' : 'none';
                btnStart.disabled = !hasEnoughPlayers;
                btnStart.style.opacity = hasEnoughPlayers ? '1' : '0.45';
                btnStart.style.cursor = hasEnoughPlayers ? 'pointer' : 'not-allowed';
                btnStart.title = hasEnoughPlayers
                    ? `Partie mit ${state.players.length} Fahrern starten`
                    : 'Mindestens 2 Fahrer (Spieler oder KI) erforderlich';
                btnStart.textContent = hasEnoughPlayers
                    ? `🚦 MOTOREN STARTEN (${state.players.length} Fahrer)`
                    : '🚦 MINDESTENS 2 FAHRER NÖTIG';
            }
            if (hintMin) {
                hintMin.style.display = (this.isHost && !hasEnoughPlayers) ? 'block' : 'none';
            }
        }

        showJoinModal(state) {
            this.ui.lobbyModal.classList.add('open');
            const formCreate = document.getElementById('lobby-create-form');
            const formWaiting = document.getElementById('lobby-waiting-room');
            const formJoin = document.getElementById('lobby-join-form');

            if (formCreate) formCreate.style.display = 'none';
            if (formWaiting) formWaiting.style.display = 'none';
            if (formJoin) formJoin.style.display = 'block';

            const roomBadge = document.getElementById('join-room-code-badge');
            if (roomBadge) roomBadge.textContent = this.roomCode;

            const joinNameInput = document.getElementById('join-player-name');
            const savedName = localStorage.getItem('kai_gh_player_name');
            if (joinNameInput && savedName && !joinNameInput.value) {
                joinNameInput.value = savedName;
            }

            const joinColors = document.querySelectorAll('#join-color-picker .hood-color-swatch');
            joinColors.forEach(swatch => {
                if (swatch._hoodBound) return;
                swatch._hoodBound = true;
                swatch.addEventListener('click', (e) => {
                    joinColors.forEach(s => s.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            const joinVehicles = document.querySelectorAll('#join-vehicle-picker .hood-vehicle-card');
            joinVehicles.forEach(card => {
                if (card._hoodBound) return;
                card._hoodBound = true;
                card.addEventListener('click', (e) => {
                    joinVehicles.forEach(c => c.classList.remove('selected'));
                    e.currentTarget.classList.add('selected');
                });
            });

            const btnJoin = document.getElementById('btn-confirm-join');
            if (btnJoin && !btnJoin._hoodBound) {
                btnJoin._hoodBound = true;
                btnJoin.addEventListener('click', async () => {
                    const name = (joinNameInput ? joinNameInput.value.trim() : '') || 'Racer';
                    const selColor = document.querySelector('#join-color-picker .hood-color-swatch.selected');
                    const color = selColor ? selColor.dataset.color : '#3b82f6';
                    const selVeh = document.querySelector('#join-vehicle-picker .hood-vehicle-card.selected');
                    const vehicle = selVeh ? selVeh.dataset.vehicle : 'muscle';
                    const errBox = document.getElementById('join-error-box');
                    if (errBox) errBox.classList.add('hidden');

                    btnJoin.disabled = true;
                    try {
                        const res = await fetch('api.php?action=join', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                room_code: this.roomCode,
                                player_name: name,
                                color: color,
                                vehicle: vehicle,
                            }),
                        });
                        const data = await res.json();
                        btnJoin.disabled = false;
                        if (data.success) {
                            localStorage.setItem('kai_gh_player_name', name);
                            this.playerToken = data.player_token;
                            this.myPlayerId = data.player_id;
                            localStorage.setItem('quarxl_hood_token_' + this.roomCode, this.playerToken);

                            if (formJoin) formJoin.style.display = 'none';

                            const mePlayer = data.state.players.find(p => p.id === this.myPlayerId);
                            this.applyState(data.state, mePlayer);
                            this.startPolling();
                        } else {
                            if (errBox) {
                                errBox.textContent = data.message || 'Beitritt fehlgeschlagen.';
                                errBox.classList.remove('hidden');
                            } else {
                                this.showToast(data.message || 'Beitritt fehlgeschlagen.', 'error');
                            }
                        }
                    } catch (e) {
                        btnJoin.disabled = false;
                        if (errBox) {
                            errBox.textContent = 'Netzwerkfehler beim Beitritt.';
                            errBox.classList.remove('hidden');
                        }
                    }
                });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        window.hoodGame = new HoodGame();
        window.hoodGame.init();
    });
})();
