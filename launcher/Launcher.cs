// Source: drez-containerize skill, templates\launcher\Launcher.cs. One source
// for every app's .exe launcher; each app embeds its own launcher.ini (the
// /resource switch in build.bat) and its own icon (/win32icon). Edit the
// template, never an app's copy, then run make-launcher.js again.
//
// Replaces the silent .vbs launchers (owner, 2026-10-16) and keeps their
// rules: everything resolves from this .exe's own folder, never a drive
// letter; Electron starts from node_modules\electron\dist\electron.exe with
// no console, window style normal and the app folder as its argument; a
// missing piece is named in a message box, never a silent failure.
//
// launcher.ini, one directive per line, read top to bottom:
//   exe=<File Name>.exe      read by build.bat only: the .exe written beside the launcher\ folder
//   icon=<file.ico>          read by build.bat only, relative to the folder holding launcher\
//                            (no icon= line: the .exe gets the Windows default icon)
//   name=<App Name>          message box title
//   appdir=<folder>          the app root, relative to the .exe (default .)
//   target=<folder>          folder passed to Electron, relative to appdir (default .)
//   cwd=temp|app|target|root working directory for Electron (default temp)
//   userdatadir=config       pass --user-data-dir=<config.local.json dataFolder>
//   fallback=<file>          started instead when a require fails and it exists
//   require=<path>|<message> stop with <message> when <path> is missing
//   ask=<path>|<message>     when <path> is missing, ask Yes/No; No stops
//   prebuild=<command>|<message>  when npm is on PATH, run <command> hidden in
//                            appdir and wait; a non-zero exit shows <message>
//   start=electron           start Electron (the default when no run= line)
//   run=<command line>       start a command with no window instead
// In messages and run= lines: {path} is the checked file, {app} the app root,
// \n a line break.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Reflection;
using System.Text.RegularExpressions;
using System.Windows.Forms;

static class Launcher
{
    static string name = "Application";
    static string exeDir, appDir, targetDir, cwdMode = "temp", fallback;
    static bool userDataDir;

    static string Expand(string s, string path)
    {
        return s.Replace("\\n", "\r\n").Replace("{path}", path ?? "").Replace("{app}", appDir);
    }

    static void Stop(string message)
    {
        MessageBox.Show(message, name, MessageBoxButtons.OK, MessageBoxIcon.Warning);
        Environment.Exit(1);
    }

    static string Full(string baseDir, string rel)
    {
        if (string.IsNullOrEmpty(rel) || rel == ".") return baseDir;
        return Path.GetFullPath(Path.Combine(baseDir, rel)).TrimEnd('\\');
    }

    static bool Exists(string p) { return File.Exists(p) || Directory.Exists(p); }

    // A backslash before a closing quote escapes the quote, so a drive root
    // ("X:\") travels as "X:\." instead.
    static string Q(string p) { return "\"" + (p.EndsWith("\\") ? p + "." : p) + "\""; }

    // config.local.json "dataFolder" resolved against the app folder; null when unusable.
    static string DataFolder()
    {
        try
        {
            string cfg = Path.Combine(appDir, "config.local.json");
            if (!File.Exists(cfg)) return null;
            Match m = Regex.Match(File.ReadAllText(cfg), @"""dataFolder""\s*:\s*""((?:[^""\\]|\\.)*)""");
            if (!m.Success) return null;
            string v = m.Groups[1].Value.Replace(@"\\", @"\").Replace('/', '\\');
            if (v.Length == 0) return null;
            if (!Path.IsPathRooted(v)) v = Path.Combine(appDir, v);
            v = Path.GetFullPath(v).TrimEnd('\\');
            if (v.Length < 3) return null;
            // The leaf may be new on a first run; its parent proves the drive is there.
            if (Directory.Exists(v) || Directory.Exists(Path.GetDirectoryName(v))) return v;
        }
        catch { }
        return null;
    }

    static void Launch(string file, string args, string cwd, bool hidden)
    {
        try
        {
            var psi = new ProcessStartInfo(file, args);
            psi.UseShellExecute = !hidden;
            psi.CreateNoWindow = hidden;
            if (hidden) psi.WindowStyle = ProcessWindowStyle.Hidden;
            psi.WorkingDirectory = cwd;
            Process.Start(psi);
        }
        catch (Exception e)
        {
            Stop(name + " could not start:\r\n" + file + "\r\n\r\n" + e.Message);
        }
    }

    static int RunHidden(string commandLine, string cwd)
    {
        try
        {
            var psi = new ProcessStartInfo("cmd.exe", "/c " + commandLine);
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.WorkingDirectory = cwd;
            using (var p = Process.Start(psi)) { p.WaitForExit(); return p.ExitCode; }
        }
        catch { return -1; }
    }

    static void StartElectron()
    {
        string electron = Path.Combine(appDir, @"node_modules\electron\dist\electron.exe");
        string args = Q(targetDir);
        if (userDataDir)
        {
            string udd = DataFolder();
            if (udd != null) args += " " + Q("--user-data-dir=" + udd);
        }
        string cwd;
        switch (cwdMode)
        {
            case "app": cwd = appDir; break;
            case "target": cwd = targetDir; break;
            case "root": cwd = Path.GetPathRoot(appDir); break;
            default: cwd = Path.GetTempPath(); break;
        }
        Launch(electron, args, cwd, false);
    }

    // run= lines: the first token (quoted or not) is the program, the rest its arguments.
    static void RunCommand(string line)
    {
        line = line.Trim();
        string file, args;
        if (line.StartsWith("\""))
        {
            int end = line.IndexOf('"', 1);
            file = line.Substring(1, end - 1);
            args = line.Substring(end + 1).Trim();
        }
        else
        {
            int sp = line.IndexOf(' ');
            file = sp < 0 ? line : line.Substring(0, sp);
            args = sp < 0 ? "" : line.Substring(sp + 1).Trim();
        }
        Launch(file, args, appDir, true);
    }

    static string[] Split(string v)
    {
        int bar = v.IndexOf('|');
        return bar < 0 ? new[] { v, "" } : new[] { v.Substring(0, bar), v.Substring(bar + 1) };
    }

    [STAThread]
    static void Main()
    {
        exeDir = Path.GetDirectoryName(Assembly.GetExecutingAssembly().Location).TrimEnd('\\');
        appDir = exeDir;
        targetDir = exeDir;

        string ini;
        using (var s = Assembly.GetExecutingAssembly().GetManifestResourceStream("launcher.ini"))
        using (var r = new StreamReader(s)) ini = r.ReadToEnd();

        var steps = new List<KeyValuePair<string, string>>();
        foreach (string raw in ini.Split('\n'))
        {
            string line = raw.Trim();
            if (line.Length == 0 || line.StartsWith("#")) continue;
            int eq = line.IndexOf('=');
            if (eq < 0) continue;
            steps.Add(new KeyValuePair<string, string>(line.Substring(0, eq).Trim().ToLowerInvariant(), line.Substring(eq + 1).Trim()));
        }

        // Settings first, so their order in the file does not matter.
        string targetRel = ".";
        foreach (var kv in steps)
        {
            switch (kv.Key)
            {
                case "name": name = kv.Value; break;
                case "appdir": appDir = Full(exeDir, kv.Value); break;
                case "target": targetRel = kv.Value; break;
                case "cwd": cwdMode = kv.Value.ToLowerInvariant(); break;
                case "userdatadir": userDataDir = kv.Value.ToLowerInvariant() == "config"; break;
                case "fallback": fallback = kv.Value; break;
            }
        }
        targetDir = Full(appDir, targetRel);

        bool started = false;
        foreach (var kv in steps)
        {
            string[] pm = Split(kv.Value);
            string path = kv.Key == "require" || kv.Key == "ask" ? Full(appDir, pm[0]) : null;
            switch (kv.Key)
            {
                case "require":
                    if (!Exists(path))
                    {
                        if (fallback != null && File.Exists(Full(appDir, fallback)))
                        {
                            Launch(Full(appDir, fallback), "", Path.GetTempPath(), false);
                            return;
                        }
                        Stop(Expand(pm[1], path));
                    }
                    break;
                case "ask":
                    if (!Exists(path) && MessageBox.Show(Expand(pm[1], path), name,
                            MessageBoxButtons.YesNo, MessageBoxIcon.Warning) != DialogResult.Yes)
                        Environment.Exit(1);
                    break;
                case "prebuild":
                    if (RunHidden("where npm >nul 2>nul", appDir) == 0 && RunHidden(pm[0], appDir) != 0 && pm[1].Length > 0)
                        MessageBox.Show(Expand(pm[1], null), name, MessageBoxButtons.OK, MessageBoxIcon.Warning);
                    break;
                case "run":
                    RunCommand(Expand(kv.Value, null));
                    started = true;
                    break;
                case "start":
                    StartElectron();
                    started = true;
                    break;
            }
        }
        if (!started) StartElectron();
    }
}
