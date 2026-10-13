(function() {
    const bgContainer = document.createElement('div');
    bgContainer.className = 'lume-hieroglyphs-bg';
    document.body.appendChild(bgContainer);

    const hieroglyphs = [
        '𓀀', '𓀁', '𓀂', '𓀃', '𓀄', '𓀅', '𓀆', '𓀇', '𓀈', '𓀉', 
        '𓃀', '𓃁', '𓃂', '𓃃', '𓃄', '𓃅', '𓃆', '𓃇', '𓃈', '𓃉', 
        '𓄀', '𓄁', '𓄂', '𓄃', '𓄄', '𓄅', '𓄆', '𓄇', '𓄈', '𓄉',
        '𓅀', '𓅁', '𓅂', '𓅃', '𓅄', '𓅅', '𓅆', '𓅇', '𓅈', '𓅉',
        '𓆀', '𓆁', '𓆂', '𓆃', '𓆄', '𓆅', '𓆆', '𓆇', '𓆈', '𓆉',
        '𓇋', '𓇌', '𓇍', '𓇎', '𓇏', '𓇐', '𓇑', '𓇒', '𓇓', '𓇔'
    ];

    const gridSize = 60;
    const numCols = Math.ceil(window.innerWidth / gridSize);
    const numRows = Math.ceil(window.innerHeight / gridSize);
    
    for (let i = 0; i < numCols; i++) {
        for (let j = 0; j < numRows; j++) {
            if (Math.random() > 0.4) continue;

            const span = document.createElement('span');
            span.className = 'lume-h-char';
            span.textContent = hieroglyphs[Math.floor(Math.random() * hieroglyphs.length)];
            
            const x = i * gridSize + (Math.random() * 30 - 15);
            const y = j * gridSize + (Math.random() * 30 - 15);
            
            span.style.left = `${x}px`;
            span.style.top = `${y}px`;
            
            const size = 1.5 + Math.random() * 2;
            span.style.fontSize = `${size}rem`;

            const waveSpeed = 250; 
            const baseDelay = x / waveSpeed;
            const randomDelay = baseDelay + (Math.random() * 0.8);
            
            // Negative delay so they are immediately animating, staggered.
            // Using modulo 6 (animation duration) to keep delays manageable.
            span.style.animationDelay = `-${randomDelay % 6}s`;
            
            bgContainer.appendChild(span);
        }
    }
})();
