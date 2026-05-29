const canvas = document.getElementById("board");
const ctx    = canvas.getContext("2d");

canvas.width  = window.innerWidth;
canvas.height = window.innerHeight;

let drawing = false;
let color   = "black";

function saveBoard() {
  localStorage.setItem(
    "whiteboard-save",
    canvas.toDataURL()
  );
}

function loadBoard(){
  const data = localStorage.getItem(
    "whiteboard-save"
  );

  if(!data) return;

  const img = new Image();

  img.onload = () => {
    ctx.drawImage(img, 0, 0);
  };

  img.src=data;
}

function draw(x,y) {
  if(!drawing) return;

  ctx.lineWidth = 6;

  ctx.lineCap = "round";

  ctx.strokeStyle = color;

  ctx.lineTo(x,y);

  ctx.stroke();

  ctx.beginPath();

  ctx.moveTo(x, y);

  saveBoard();
}

canvas.addEventListener("mousedown", () => {
  drawing = true;
});

canvas.addEventListener("mouseup", () => {
  drawing = false;
  ctx.beginPath();
});

canvas.addEventListener("mousemove", e => {
  draw(e.clientX, e.clientY);
});

document.getElementById("pen").onclick = () => {
  color = "black";
};

document.getElementById("erase").onclick = () => {
  color="white";
};

document.getElementById("clear").onclick = () => {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  localStorage.removeItem("whiteboard-save");
};

document.getElementById("save").onclick = () => {
  const a = document.createElement("a");
  a.href = canvas.toDataURL();
  a.download = "whiteboard.png";
  a.click();
};

loadBoard();
