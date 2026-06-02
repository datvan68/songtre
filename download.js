const ftp = require("basic-ftp");
const fs = require("fs");

async function download() {
    const client = new ftp.Client();
    try {
        console.log("Đang kết nối vào Hosting FTP...");
        await client.access({
            host: "103.167.89.124",
            user: "songtrensg",
            password: "YnJWXnn2M3",
            secure: false
        });
        
        console.log("Kết nối thành công! Đang xác định thư mục mã nguồn...");
        const list = await client.list();
        let targetPath = "public_html";
        
        // Kiểm tra xem có cấu trúc domains/.../public_html của DirectAdmin không
        const domainsDir = list.find(i => i.name === "domains");
        if (domainsDir) {
            const domainsList = await client.list("domains");
            const mainDomain = domainsList.find(i => i.isDirectory);
            if (mainDomain) {
                targetPath = `domains/${mainDomain.name}/public_html`;
            }
        }
        
        console.log(`Đã xác định thư mục cần tải: ${targetPath}`);
        
        const localPath = "d:\\Download\\songtrennsg\\public_html";
        if (!fs.existsSync(localPath)) {
            fs.mkdirSync(localPath, { recursive: true });
        }
        
        console.log(`Đang sao chép toàn bộ mã nguồn về thư mục máy bạn: ${localPath}...`);
        console.log(`Tiến trình tải đang chạy. Vui lòng chờ...`);
        
        // Bắt đầu tải đệ quy
        await client.downloadToDir(localPath, targetPath);
        console.log("============= TẢI XONG TOÀN BỘ MÃ NGUỒN =============");
    } catch (err) {
        console.error("Lỗi quá trình tải:", err);
    }
    client.close();
}

download();
