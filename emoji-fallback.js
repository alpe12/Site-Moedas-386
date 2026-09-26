(function() {
  // Add any emojis you want to test and fallback for
  const missingEmojis = ['🪙'];

  function supportsEmoji(emoji) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx) return false;
    
    canvas.width = 20;
    canvas.height = 20;
    ctx.fillStyle = '#000000';
    ctx.textBaseline = 'middle';
    ctx.font = '16px "Segoe UI Emoji", "Apple Color Emoji", sans-serif';
    ctx.fillText(emoji, 0, 10);
    
    const data = ctx.getImageData(0, 0, 20, 20).data;
    for (let i = 0; i < data.length; i += 4) {
      const r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
      // If colored pixels exist, the native emoji rendered properly
      if (a > 0 && !(r === g && g === b)) return true;
    }
    return false;
  }

  // Check if ANY emoji in our list fails native rendering
  const unsupported = missingEmojis.filter(emoji => !supportsEmoji(emoji));

  if (unsupported.length > 0) {
    // Join the missing emojis into a single string
    const emojiString = unsupported.join('');
    
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    // encodeURIComponent keeps the URL valid while accepting literal emojis
    link.href = `https://fonts.googleapis.com/css2?family=Noto+Color+Emoji&text=${encodeURIComponent(emojiString)}&display=swap`;
    document.head.appendChild(link);
  }
})();
