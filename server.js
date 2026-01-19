import "./db.js";
import express from "express";
import bodyParser from "body-parser";
import cors from "cors";
import axios from "axios";

import Cliente from "./models/Cliente.js";
import Motorista from "./models/Motorista.js";
import { Atribuicao } from "./models/Atribuicao.js";



import Mensagem from "./models/Mensagem.js";
import { pendentes, motoristas, atribuicoes, atribuirCliente } from "./database.js";

// Carrega as atribuições salvas do MongoDB
Atribuicao.find().then((docs) => {
  docs.forEach((doc) => {
    atribuicoes[doc.cliente] = doc.motorista;
  });
  console.log("✅ Atribuições carregadas do banco.");
});

const app = express();
app.use(cors());
app.use(bodyParser.json());

const ZAPI_BASE = "https://api.z-api.io/instances/3ED5449C491BB12A2910D66739CEE648/token/99865543D794C144ECA83BC3";

// Envia mensagem usando a API da Z-API
async function sendText(phone, text) {
  try {
    await axios.post(`${ZAPI_BASE}/send-text`, {
      phone,
      message: text
    });
  } catch (e) {
    console.error("Erro enviando mensagem:", e.response?.data || e.message);
  }
}

// Webhook que recebe mensagens do WhatsApp
app.post("/webhook", async (req, res) => {
  const { from, body } = req.body;
  const message = body?.message?.text || body?.text || "mensagem-desconhecida";

  console.log(`Mensagem de ${from}: ${message}`);

  // Salva a mensagem recebida
  await Mensagem.create({
    de: from,
    para: atribuicoes[from] || "pendente",
    conteudo: message,
    data: new Date()
  });

  // Se não estiver atribuído ainda, adiciona aos pendentes
  if (!atribuicoes[from]) {
    if (!pendentes.includes(from)) pendentes.push(from);
    return res.sendStatus(200);
  }

  const para = atribuicoes[from];

  // Encaminha a mensagem para o motorista ou cliente correspondente
  await sendText(para, message);

  res.sendStatus(200);
});

// Lista de clientes pendentes
app.get("/pendentes", (req, res) => res.json(pendentes));

// Lista de motoristas cadastrados
app.get("/motoristas", (req, res) => res.json(motoristas));

// Atribui cliente a motorista
app.post("/atribuir", async (req, res) => {
  const { cliente, motorista } = req.body;

  // Salva no banco de dados
  await Atribuicao.create({ cliente, motorista });

  // Atualiza em memória
  atribuicoes[cliente] = motorista;

  res.json({ ok: true });
});

// ✅ Novo endpoint para buscar o histórico de mensagens
app.get("/historico", async (req, res) => {
  const { cliente, motorista } = req.query;

  if (!cliente || !motorista) {
    return res.status(400).json({ erro: "Parâmetros 'cliente' e 'motorista' são obrigatórios." });
  }

  try {
    const mensagens = await Mensagem.find({
      $or: [
        { de: cliente, para: motorista },
        { de: motorista, para: cliente }
      ]
    }).sort({ data: 1 });

    res.json(mensagens);
  } catch (err) {
    console.error("Erro ao buscar histórico:", err);
    res.status(500).json({ erro: "Erro interno ao buscar histórico." });
  }
});

// Inicia o servidor
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 Servidor rodando na porta ${PORT}`));
