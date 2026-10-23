using System;
using System.Collections.Generic;
using System.Globalization;
using System.Speech.Recognition;
using System.Text;
using System.Threading;

// Reconnaissance SAPI fr-FR sur un fichier WAV : dictée libre, ou grammaire contrainte
// (les propositions du script, répétées) pour obtenir la position de chaque mot.
public static class Aligner
{
    public static string Run(string wav, string mode, string[] phrases)
    {
        var sb = new StringBuilder();
        var fr = new CultureInfo("fr-FR");
        var inv = CultureInfo.InvariantCulture;
        using (var eng = new SpeechRecognitionEngine(fr))
        {
            if (mode == "dictee")
            {
                eng.LoadGrammar(new DictationGrammar());
            }
            else
            {
                var choices = new Choices(phrases);
                var one = new GrammarBuilder(choices);
                one.Culture = fr;
                var gb = new GrammarBuilder();
                gb.Culture = fr;
                gb.Append(one, 1, 30);
                eng.LoadGrammar(new Grammar(gb));
            }
            eng.SetInputToWaveFile(wav);
            eng.InitialSilenceTimeout = TimeSpan.Zero;
            eng.BabbleTimeout = TimeSpan.Zero;
            eng.EndSilenceTimeout = TimeSpan.FromMilliseconds(300);
            eng.EndSilenceTimeoutAmbiguous = TimeSpan.FromMilliseconds(600);
            var done = new ManualResetEvent(false);
            eng.SpeechRecognized += (s, e) =>
            {
                var r = e.Result;
                sb.AppendLine("R\t" + r.Audio.AudioPosition.TotalSeconds.ToString("0.000", inv) + "\t"
                    + r.Audio.Duration.TotalSeconds.ToString("0.000", inv) + "\t"
                    + r.Confidence.ToString("0.00", inv) + "\t" + r.Text);
                foreach (var w in r.Words)
                {
                    var a = r.GetAudioForWordRange(w, w);
                    sb.AppendLine("W\t" + a.AudioPosition.TotalSeconds.ToString("0.000", inv) + "\t"
                        + a.Duration.TotalSeconds.ToString("0.000", inv) + "\t"
                        + w.Confidence.ToString("0.00", inv) + "\t" + w.Text);
                }
            };
            eng.SpeechRecognitionRejected += (s, e) =>
            {
                var r = e.Result;
                string t = (r == null) ? "" : r.Text;
                string pos = "";
                if (r != null && r.Audio != null)
                    pos = r.Audio.AudioPosition.TotalSeconds.ToString("0.000", inv) + "\t"
                        + r.Audio.Duration.TotalSeconds.ToString("0.000", inv);
                sb.AppendLine("X\t" + pos + "\t" + t);
            };
            eng.RecognizeCompleted += (s, e) => done.Set();
            eng.RecognizeAsync(RecognizeMode.Multiple);
            done.WaitOne();
        }
        return sb.ToString();
    }
}
