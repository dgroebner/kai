document.addEventListener('DOMContentLoaded', function () {
    // Letzte bekannte ID initialisieren
    let lastActivityId = parseInt(document.body.dataset.lastActivityId || 0, 10);

    function pollNewActivities() {
        fetch(`/system/api.php?last_id=${lastActivityId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Netzwerkfehler beim Activity-Polling');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    data.activities.forEach(activity => {
                        showNotificationBubble(activity.message, activity.link_url);

                        if (activity.id > lastActivityId) {
                            lastActivityId = parseInt(activity.id, 10);
                        }
                    });
                }
            })
            .catch(error => {
                console.debug('Activity Polling Info:', error);
            });
    }

    function showNotificationBubble(text, link) {
        let container = document.getElementById('activity-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'activity-toast-container';
            container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 350px;';
            document.body.appendChild(container);
        }

        // Bubble-Element im Look & Feel der KPI-Karten
        const bubble = document.createElement('div');
        bubble.style.cssText = `
            background-color: #1a1d24; 
            color: #ffffff; 
            padding: 16px; 
            border-radius: 10px; 
            border: 1px solid #1b4b8a; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.3); 
            font-size: 0.9rem; 
            opacity: 0; 
            transition: opacity 0.3s ease, transform 0.3s ease; 
            transform: translateY(10px);
        `;

        const escape = window.KaiHtml && window.KaiHtml.escape ? window.KaiHtml.escape : (str => String(str ?? ''));

        // Kleiner KPI-ähnlicher Header ("AKTIVITÄT") gefolgt vom Text
        let content = `
            <div style="font-size: 0.75rem; font-weight: 600; letter-spacing: 0.05em; color: #8a9ba8; margin-bottom: 6px; text-transform: uppercase;">
                Aktivität
            </div>
            <div style="color: #e2e8f0; line-height: 1.4;">
                ${escape(text)}
            </div>
        `;

        if (link) {
            content += `
                <div style="margin-top: 10px; font-size: 0.85rem;">
                    <a href="${escape(link)}" style="color: #3b82f6; text-decoration: none; font-weight: 500;">
                        Details ansehen &rarr;
                    </a>
                </div>
            `;
        }

        bubble.innerHTML = content;
        container.appendChild(bubble);

        requestAnimationFrame(() => {
            bubble.style.opacity = '1';
            bubble.style.transform = 'translateY(0)';
        });

        setTimeout(() => {
            bubble.style.opacity = '0';
            bubble.style.transform = 'translateY(10px)';
            setTimeout(() => bubble.remove(), 300);
        }, 6000);
    }

    setInterval(pollNewActivities, 10000);
});