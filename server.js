const express = require("express");
const ExcelJS = require("exceljs");
const path = require("path");
const crypto = require("crypto");

const app = express();
const PORT = 3000;

const PRODUCTS_FILE = path.join(__dirname, "products.xlsx");
const ORDERS_FILE = path.join(__dirname, "endExcel.xlsx");

app.use(express.json());
app.use(express.static(path.join(__dirname, "public")));

function generateUniqueId() {
  return "ORD-" + crypto.randomBytes(5).toString("hex").toUpperCase();
}

app.get("/api/products", async (req, res) => {
  try {
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.readFile(PRODUCTS_FILE);

    const worksheet = workbook.worksheets[0];
    const products = [];

    worksheet.eachRow((row, rowNumber) => {
      if (rowNumber === 1) return;

      products.push({
        serialNumber: row.getCell(1).value,
        name: row.getCell(2).value,
        description: row.getCell(3).value,
        price: Number(row.getCell(4).value) || 0
      });
    });

    res.json(products);
  } catch (error) {
    console.error(error);
    res.status(500).json({ error: "Unable to read products.xlsx" });
  }
});

app.post("/api/order", async (req, res) => {
  try {
    const { address, phone, products } = req.body;

    if (!address || !phone || !Array.isArray(products) || products.length === 0) {
      return res.status(400).json({
        error: "Address, phone number and at least one product are required."
      });
    }

    const orderId = generateUniqueId();

    const workbook = new ExcelJS.Workbook();

    try {
      await workbook.xlsx.readFile(ORDERS_FILE);
    } catch {
      const worksheet = workbook.addWorksheet("Orders");

      worksheet.columns = [
        { header: "Unique ID", key: "uniqueId", width: 20 },
        { header: "Product Serial Number", key: "serialNumber", width: 25 },
        { header: "Quantity", key: "quantity", width: 12 },
        { header: "Address", key: "address", width: 40 },
        { header: "Phone Number", key: "phone", width: 20 },
        { header: "Order Date", key: "date", width: 25 }
      ];
    }

    const worksheet = workbook.worksheets[0];

    products.forEach(product => {
      worksheet.addRow({
        uniqueId: orderId,
        serialNumber: product.serialNumber,
        quantity: Number(product.quantity),
        address,
        phone,
        date: new Date().toISOString()
      });
    });

    await workbook.xlsx.writeFile(ORDERS_FILE);

    res.json({
      success: true,
      uniqueId: orderId,
      message: "Order submitted successfully."
    });
  } catch (error) {
    console.error(error);
    res.status(500).json({ error: "Unable to save order." });
  }
});

app.listen(PORT, () => {
  console.log(`Server running at http://localhost:${PORT}`);
});
