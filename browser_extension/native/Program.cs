using Newtonsoft.Json;
using System.Diagnostics;
using System.Text;

namespace native
{
	internal class Program
	{
		static void Main(string[] args)
		{
			var token = GetCommandOutput();
			SendMessage(token);
		}

		static string GetCommandOutput()
		{
			var processInfo = new ProcessStartInfo("cmd.exe", "/c " + "gcloud auth print-access-token")
			{
				CreateNoWindow = true,
				UseShellExecute = false,
				RedirectStandardOutput = true,
				RedirectStandardError = true
			};

			var process = Process.Start(processInfo);
			string output = process.StandardOutput.ReadToEnd().Replace("\r\n", "");
			string error = process.StandardError.ReadToEnd();
			process.WaitForExit();

			if(!string.IsNullOrEmpty(error))
			{
				throw new Exception($"Command execution error: {error}");
			}

			return output;
		}

		static void SendMessage(string message)
		{
			string messageJson = JsonConvert.SerializeObject(message, Formatting.None);
			byte[] messageBytes = Encoding.UTF8.GetBytes(messageJson);

			var stdout = Console.OpenStandardOutput();

			stdout.WriteByte((byte)((messageBytes.Length >> 0) & 0xFF));
			stdout.WriteByte((byte)((messageBytes.Length >> 8) & 0xFF));
			stdout.WriteByte((byte)((messageBytes.Length >> 16) & 0xFF));
			stdout.WriteByte((byte)((messageBytes.Length >> 24) & 0xFF));
			stdout.Write(messageBytes, 0, messageBytes.Length);
			stdout.Flush();
		}
	}
}
