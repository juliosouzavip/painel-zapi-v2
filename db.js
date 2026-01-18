import mongoose from "mongoose";

const uri = "mongodb+srv://juliosouza:<SUA_SENHA>@cluster0.52o4tn0.mongodb.net/?retryWrites=true&w=majority";

// Conecta ao MongoDB
mongoose.connect(uri, {
  useNewUrlParser: true,
  useUnifiedTopology: true,
})
.then(() => console.log("🟢 Conectado ao MongoDB Atlas"))
.catch((err) => console.error("🔴 Erro ao conectar:", err));
