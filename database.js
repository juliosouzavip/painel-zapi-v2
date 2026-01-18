export const pendentes = [];

export const motoristas = [
  { nome: "Motorista A", telefone: "5511988880001" },
  { nome: "Motorista B", telefone: "5511977770002" },
  { nome: "Motorista C", telefone: "5511966660003" }
];

export const atribuicoes = {};

export function atribuirCliente(cliente, motorista) {
  atribuicoes[cliente] = motorista;
  atribuicoes[motorista] = cliente;

  const index = pendentes.indexOf(cliente);
  if (index !== -1) pendentes.splice(index, 1);
}
