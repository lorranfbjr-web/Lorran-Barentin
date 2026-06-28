// Plugin de exemplo: Gradiente Noturno
// Para usar, carregue este arquivo na seção "Plugins & Modelos" do gerador.

JR.registerPlugin({
  id: 'gradiente-noturno',
  name: 'Gradiente Noturno',
  version: '1.0',
  description: 'Modelo com fundo gradiente escuro e texto centralizado',
  tags: ['TECNOLOGIA', 'SAÚDE'],
  models: [
    {
      id: 'gradiente_noturno',
      name: 'Gradiente Noturno',
      file: 'story_gradiente_noturno.png',
      requiresPhoto: true,
      render: function(params) {
        var W = params.W, H = params.H;
        var h = JR.helpers;

        var canvas = document.createElement('canvas');
        canvas.width = W;
        canvas.height = H;
        var ctx = canvas.getContext('2d');

        // Dark gradient background
        var grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, '#0D0D2B');
        grad.addColorStop(0.5, '#1A1A4E');
        grad.addColorStop(1, '#0D2481');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, W, H);

        // Photo in top portion with rounded corners mask
        var photoH = Math.round(H * 0.45);
        var margin = 50;
        ctx.save();
        ctx.beginPath();
        ctx.roundRect(margin, margin, W - margin * 2, photoH, 24);
        ctx.clip();
        h.drawPhotoCropped(ctx, params.photo, margin, margin, W - margin * 2, photoH);
        ctx.restore();

        // Subtle border around photo
        ctx.strokeStyle = 'rgba(24,173,254,0.4)';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.roundRect(margin, margin, W - margin * 2, photoH, 24);
        ctx.stroke();

        // Tag
        var tagY = margin + photoH + 60;
        var tagDims = h.drawTag(ctx, params.tag, 70, tagY, '#18ADFE', '#FFFFFF');

        // Title
        h.drawTitle(ctx, params.title, 70, tagY + tagDims.h + 30, W - 140, 58, 74, '#FFFFFF', {
          ox: 2, oy: 2, alpha: 100
        });

        // Circular logo
        h.drawCircularLogo(ctx, params.logos.circular, 50, H - 180, 80);

        // Footer logo
        var fl = params.logos.footWhiteSmall;
        ctx.drawImage(fl, Math.round((W - fl.width) / 2), H - 80);

        return canvas;
      }
    }
  ]
});
